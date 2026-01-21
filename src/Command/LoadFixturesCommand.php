<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DoctrineConfigLoader;
use App\Tests\Fixtures\DatabaseFixtures;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Dotenv\Dotenv;

#[AsCommand(
    name: 'database:fixtures:load',
    description: 'Load database fixtures for testing',
)]
final class LoadFixturesCommand extends Command
{
    public function __construct(
        private DoctrineConfigLoader $configLoader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'connection',
                'c',
                InputOption::VALUE_REQUIRED,
                'Load fixtures for a specific connection (default: all connections)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Always load .env.test to ensure we have the correct configuration for fixtures
        $projectDir = \dirname(__DIR__, 2);
        if (file_exists($projectDir.'/.env.test')) {
            (new Dotenv())->overload($projectDir.'/.env.test');
        }

        try {
            $this->configLoader->loadAndValidate();
        } catch (\Exception $e) {
            $io->error(\sprintf('Failed to load database configuration: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $connectionName = $input->getOption('connection');

        if (null !== $connectionName && !\is_string($connectionName)) {
            $io->error('Connection name must be a string.');

            return Command::FAILURE;
        }

        $connections = null !== $connectionName
            ? [$connectionName => $this->configLoader->getConnection($connectionName)]
            : $this->configLoader->getAllConnections();

        $io->title('Loading Database Fixtures');

        foreach ($connections as $name => $connection) {
            $io->section(\sprintf('Connection: %s', $name));

            try {
                DatabaseFixtures::teardown($connection);
                DatabaseFixtures::setup($connection);

                // Detect database type from connection params
                /** @var array{driver?: string, url?: string} $params */
                $params = $connection->getParams();
                $type = 'unknown';
                if (isset($params['driver']) && \is_string($params['driver'])) {
                    $type = $params['driver'];
                } elseif (isset($params['url']) && \is_string($params['url'])) {
                    if (str_starts_with($params['url'], 'mysql://') || str_starts_with($params['url'], 'pdo-mysql://')) {
                        $type = 'pdo_mysql';
                    } elseif (str_starts_with($params['url'], 'postgresql://') || str_starts_with($params['url'], 'postgres://') || str_starts_with($params['url'], 'pdo-pgsql://')) {
                        $type = 'pdo_pgsql';
                    } elseif (str_starts_with($params['url'], 'sqlite://') || str_starts_with($params['url'], 'pdo-sqlite://')) {
                        $type = 'pdo_sqlite';
                    } elseif (str_starts_with($params['url'], 'sqlsrv://') || str_starts_with($params['url'], 'pdo-sqlsrv://')) {
                        $type = 'pdo_sqlsrv';
                    }
                }

                $userCount = \count(DatabaseFixtures::getExpectedUsers($type));
                $productCount = \count(DatabaseFixtures::getExpectedProducts($type));

                $io->success([
                    'Schema created successfully',
                    \sprintf('Loaded %d users', $userCount),
                    \sprintf('Loaded %d products', $productCount),
                ]);
            } catch (\Exception $e) {
                $io->error(\sprintf('Failed to load fixtures for connection "%s": %s', $name, $e->getMessage()));

                return Command::FAILURE;
            }
        }

        $io->success(\sprintf('Fixtures loaded successfully for %d connection(s)', \count($connections)));

        return Command::SUCCESS;
    }
}
