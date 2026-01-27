<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\PIIGroup;
use App\Enum\PIILabel;
use App\Service\DoctrineConfigLoader;
use App\Service\PIIAnalyzerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pii:discover',
    description: 'Scan database tables for Personally Identifiable Information (PII) using GLiNER-PII model',
)]
class PIIDiscoveryCommand extends Command
{
    private const DEFAULT_SAMPLE_SIZE = 50;
    private const DEFAULT_THRESHOLD = 0.9;

    public function __construct(
        private LoggerInterface $logger,
        private DoctrineConfigLoader $doctrineConfigLoader,
        private PIIAnalyzerService $piiAnalyzer,
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
                'Database connection name to scan'
            )
            ->addOption(
                'tables',
                't',
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of specific tables to scan (default: all tables)'
            )
            ->addOption(
                'sample-size',
                's',
                InputOption::VALUE_REQUIRED,
                'Number of random rows to sample per table',
                (string) self::DEFAULT_SAMPLE_SIZE
            )
            ->addOption(
                'confidence-threshold',
                null,
                InputOption::VALUE_REQUIRED,
                'Minimum confidence score (0.0-1.0) to flag PII',
                (string) self::DEFAULT_THRESHOLD
            )
            ->setHelp($this->getHelpText());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Validate connection option
        $connectionName = $input->getOption('connection');
        if (!\is_string($connectionName) || '' === $connectionName) {
            $io->error('The --connection option is required.');

            return Command::FAILURE;
        }

        // Parse options
        $tablesOption = $input->getOption('tables');
        $tablesToScan = null;
        if (\is_string($tablesOption) && '' !== $tablesOption) {
            $tablesToScan = array_map('trim', explode(',', $tablesOption));
        }

        $sampleSize = (int) $input->getOption('sample-size');
        if ($sampleSize < 1) {
            $sampleSize = self::DEFAULT_SAMPLE_SIZE;
        }

        $threshold = (float) $input->getOption('confidence-threshold');
        if ($threshold < 0.0 || $threshold > 1.0) {
            $io->error('Confidence threshold must be between 0.0 and 1.0');

            return Command::FAILURE;
        }

        try {
            // Load database configuration
            $this->doctrineConfigLoader->loadAndValidate();

            // Validate connection exists
            if (!\in_array($connectionName, $this->doctrineConfigLoader->getConnectionNames(), true)) {
                $availableConnections = implode(', ', $this->doctrineConfigLoader->getConnectionNames());
                $io->error(\sprintf(
                    'Connection "%s" not found. Available connections: %s',
                    $connectionName,
                    $availableConnections
                ));

                return Command::FAILURE;
            }

            $connection = $this->doctrineConfigLoader->getConnection($connectionName);

            // Get tables to scan
            $allTables = $this->doctrineConfigLoader->getTableNames($connectionName);

            if ([] === $allTables) {
                $io->warning(\sprintf('Connection "%s" has no tables.', $connectionName));

                return Command::SUCCESS;
            }

            if (null !== $tablesToScan) {
                $tables = array_intersect($tablesToScan, $allTables);
                $notFound = array_diff($tablesToScan, $allTables);

                if ([] !== $notFound) {
                    $io->warning(\sprintf('Tables not found: %s', implode(', ', $notFound)));
                }

                if ([] === $tables) {
                    $io->error('None of the specified tables were found.');

                    return Command::FAILURE;
                }
            } else {
                $tables = $allTables;
            }

            $io->title('PII Discovery');
            $io->text(\sprintf('Connection: <info>%s</info>', $connectionName));
            $io->text(\sprintf('Tables to scan: <info>%d</info>', \count($tables)));
            $io->text(\sprintf('Sample size: <info>%d</info> rows per table', $sampleSize));
            $io->text(\sprintf('Confidence threshold: <info>%.1f%%</info>', $threshold * 100));
            $io->newLine();

            // Start GLiNER analyzer
            $io->text('Starting GLiNER PII analyzer...');
            $this->piiAnalyzer->start();
            $io->text('<info>GLiNER ready</info>');
            $io->newLine();

            /** @var array<string, array<string, list<string>>> $results */
            $results = [];

            foreach ($tables as $tableName) {
                $io->write(\sprintf('Processing table <info>%s</info>... ', $tableName));
                $this->logger->info(\sprintf('Processing table: %s', $tableName));

                try {
                    // Get random sample of rows
                    $rows = $this->fetchSampleRows($connection, $tableName, $sampleSize);

                    if ([] === $rows) {
                        $io->writeln('Skipped (empty)');
                        continue;
                    }

                    // Extract column names and data
                    $columns = array_keys($rows[0]);
                    $data = array_map('array_values', $rows);

                    // Analyze with GLiNER
                    $analysis = $this->piiAnalyzer->analyze($tableName, $columns, $data, $threshold);

                    $io->writeln('Done');

                    if ([] !== $analysis['results']) {
                        $results[$tableName] = $analysis['results'];

                        $tableRows = [];
                        foreach ($analysis['results'] as $column => $piiTypes) {
                            $sample = $analysis['samples'][$column] ?? 'N/A';
                            // Truncate long samples
                            if (\strlen($sample) > 50) {
                                $sample = substr($sample, 0, 47).'...';
                            }
                            $tableRows[] = [
                                $column,
                                implode(', ', $piiTypes),
                                $sample,
                            ];
                        }

                        $io->table(
                            ['Column', 'PII Type(s)', 'Sample'],
                            $tableRows
                        );
                    }
                } catch (\Throwable $e) {
                    $this->piiAnalyzer->stop();
                    $io->newLine();
                    $io->error(\sprintf('Error processing table "%s": %s', $tableName, $e->getMessage()));

                    return Command::FAILURE;
                }
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            // $this->piiAnalyzer->stop();
            $this->logger->error('PII discovery failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchSampleRows(\Doctrine\DBAL\Connection $connection, string $tableName, int $limit): array
    {
        $platform = $connection->getDatabasePlatform();
        $quotedTable = $connection->quoteIdentifier($tableName);

        // Build a randomized query based on database platform
        $sql = match (true) {
            str_contains($platform::class, 'MySQL') => \sprintf(
                'SELECT * FROM %s ORDER BY RAND() LIMIT %d',
                $quotedTable,
                $limit
            ),
            str_contains($platform::class, 'PostgreSQL') => \sprintf(
                'SELECT * FROM %s ORDER BY RANDOM() LIMIT %d',
                $quotedTable,
                $limit
            ),
            str_contains($platform::class, 'SQLite') => \sprintf(
                'SELECT * FROM %s ORDER BY RANDOM() LIMIT %d',
                $quotedTable,
                $limit
            ),
            str_contains($platform::class, 'SQLServer') => \sprintf(
                'SELECT TOP %d * FROM %s ORDER BY NEWID()',
                $limit,
                $quotedTable
            ),
            default => \sprintf('SELECT * FROM %s LIMIT %d', $quotedTable, $limit),
        };

        $result = $connection->executeQuery($sql);

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->fetchAllAssociative();

        return $rows;
    }

    private function getHelpText(): string
    {
        $groupedLabels = PIILabel::getGroupedLabels();

        $lines = [
            'Scans database tables for Personally Identifiable Information (PII) and',
            'Protected Health Information (PHI) using NVIDIA\'s GLiNER-PII model.',
            '',
            '<info>Requirements:</info>',
            '  - Python 3.8+ with GLiNER library installed',
            '  - Run: pip install -r scripts/requirements.txt',
            '',
            '<info>Examples:</info>',
            '  <comment>php bin/console pii:discover --connection=production</comment>',
            '  <comment>php bin/console pii:discover -c production --tables=users,orders</comment>',
            '  <comment>php bin/console pii:discover -c production -s 100 --confidence-threshold=0.8</comment>',
            '',
            '<info>PII/PHI Entity Types (by category):</info>',
        ];

        foreach (PIIGroup::cases() as $group) {
            $labels = $groupedLabels[$group->value] ?? [];
            if ([] !== $labels) {
                $lines[] = \sprintf('  <comment>%s:</comment>', $group->value);
                $lines[] = \sprintf('    %s', implode(', ', $labels));
            }
        }

        return implode("\n", $lines);
    }
}
