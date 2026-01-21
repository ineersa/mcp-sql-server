<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ComposerMetadataExtractor;
use App\Service\DoctrineConfigLoader;
use App\Tools\QueryTool;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'database-mcp',
    description: 'Entrypoint MCP command',
)]
class DatabaseMcpCommand extends Command
{
    public const TEST2 = 'test2';

    protected const TEST = 'test';

    public function __construct(
        private LoggerInterface $logger,
        private ContainerInterface $container,
        private ComposerMetadataExtractor $composerMetadataExtractor,
        private DoctrineConfigLoader $doctrineConfigLoader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    /**
     * @param ConsoleOutput $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Load and validate database connections
            $this->doctrineConfigLoader->loadAndValidate();

            // Generate dynamic description with available connections
            $connectionNames = $this->doctrineConfigLoader->getConnectionNames();
            $connectionInfo = [];
            foreach ($connectionNames as $name) {
                $type = $this->doctrineConfigLoader->getConnectionType($name);
                $connectionInfo[] = \sprintf('%s (%s)', $name, $type ?? 'unknown');
            }

            $description = $this->composerMetadataExtractor->getDescription();
            if ([] !== $connectionInfo) {
                $description .= \sprintf(' | Available connections: %s', implode(', ', $connectionInfo));
            }

            $server = Server::builder()
                ->setServerInfo(
                    name: $this->composerMetadataExtractor->getName(),
                    version: $this->composerMetadataExtractor->getVersion(),
                    description: $description,
                )
                ->setLogger($this->logger)
                ->setContainer($this->container)
                ->setProtocolVersion(ProtocolVersion::V2024_11_05)
                ->addTool(
                    handler: QueryTool::class,
                    name: QueryTool::NAME,
                    description: QueryTool::getDescription($this->doctrineConfigLoader),
                    annotations: new ToolAnnotations(
                        title: QueryTool::TITLE,
                        readOnlyHint: true,
                        idempotentHint: true,
                        destructiveHint: false,
                        openWorldHint: false
                    ),
                )
                ->build();

            $transport = new StdioTransport(
                logger: $this->logger,
            );

            $server->run($transport);
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'trace' => $e->getTrace(),
            ]);
            $output->getErrorOutput()->writeln(json_encode([
                'error' => $e->getMessage(),
            ]));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
