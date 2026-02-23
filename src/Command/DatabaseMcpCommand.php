<?php

declare(strict_types=1);

namespace App\Command;

use App\Resources\ConnectionResource;
use App\Resources\RoutinesResource;
use App\Resources\TableResource;
use App\Resources\ViewsResource;
use App\Service\ComposerMetadataExtractor;
use App\Service\DatabaseSchemaService;
use App\Service\DoctrineConfigLoader;
use App\Tools\QueryTool;
use App\Tools\SchemaTool;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'database-mcp',
    description: 'Entrypoint MCP command',
)]
class DatabaseMcpCommand extends Command
{
    public function __construct(
        private LoggerInterface $logger,
        private ContainerInterface $container,
        private ComposerMetadataExtractor $composerMetadataExtractor,
        private DoctrineConfigLoader $doctrineConfigLoader,
        private DatabaseSchemaService $databaseSchemaService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->doctrineConfigLoader->loadAndValidate();

            $builder = Server::builder()
                ->setServerInfo(
                    $this->composerMetadataExtractor->getName(),
                    $this->composerMetadataExtractor->getVersion(),
                    'Run SQL query against chosen database connection.',
                )
                ->setLogger($this->logger)
                ->setContainer($this->container)
                ->setProtocolVersion(ProtocolVersion::V2025_06_18)
                ->addTool(
                    QueryTool::class,
                    QueryTool::NAME,
                    QueryTool::getDescription($this->doctrineConfigLoader),
                    new ToolAnnotations(
                        QueryTool::TITLE,
                        true,
                        true,
                        false,
                        false
                    ),
                    null,
                    null,
                    null,
                )
                ->addTool(
                    SchemaTool::class,
                    SchemaTool::NAME,
                    SchemaTool::DESCRIPTION,
                    new ToolAnnotations(
                        SchemaTool::TITLE,
                        true,
                        false,
                        false,
                        false
                    ),
                    null,
                    null,
                    null,
                );

            foreach ($this->doctrineConfigLoader->getConnectionNames() as $connectionName) {
                $builder->addResource(
                    function (string $uri) use ($connectionName): string {
                        try {
                            $resource = new ConnectionResource($this->doctrineConfigLoader);

                            return $resource($connectionName);
                        } catch (\Throwable $e) {
                            $this->logger->error('Resource read failed', [
                                'uri' => $uri,
                                'connection' => $connectionName,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                            ]);
                            throw $e;
                        }
                    },
                    "db://{$connectionName}",
                    $connectionName,
                    ConnectionResource::DESCRIPTION,
                    mimeType: 'text/plain',
                );

                $builder->addResource(
                    function (string $uri) use ($connectionName): string {
                        try {
                            $resource = new ViewsResource($this->databaseSchemaService, $this->doctrineConfigLoader);

                            return $resource($connectionName);
                        } catch (\Throwable $e) {
                            $this->logger->error('Views resource read failed', [
                                'uri' => $uri,
                                'connection' => $connectionName,
                                'error' => $e->getMessage(),
                            ]);
                            throw $e;
                        }
                    },
                    "db://{$connectionName}/views",
                    "{$connectionName}_views",
                    ViewsResource::DESCRIPTION,
                    mimeType: 'text/plain',
                );

                $builder->addResource(
                    function (string $uri) use ($connectionName): string {
                        try {
                            $resource = new RoutinesResource($this->databaseSchemaService, $this->doctrineConfigLoader);

                            return $resource($connectionName);
                        } catch (\Throwable $e) {
                            $this->logger->error('Routines resource read failed', [
                                'uri' => $uri,
                                'connection' => $connectionName,
                                'error' => $e->getMessage(),
                            ]);
                            throw $e;
                        }
                    },
                    "db://{$connectionName}/routines",
                    "{$connectionName}_routines",
                    RoutinesResource::DESCRIPTION,
                    mimeType: 'text/plain',
                );
            }

            $builder->addResourceTemplate(
                TableResource::class,
                TableResource::URI_TEMPLATE,
                TableResource::NAME,
                TableResource::DESCRIPTION,
                mimeType: 'text/plain',
            );

            $server = $builder->build();

            $transport = new \App\Transport\LoggingStdioTransport(
                logger: $this->logger,
            );

            $server->run($transport);
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'trace' => $e->getTrace(),
            ]);
            \assert($output instanceof ConsoleOutputInterface);
            $output->getErrorOutput()->writeln(json_encode([
                'error' => $e->getMessage(),
            ]));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
