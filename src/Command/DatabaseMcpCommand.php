<?php

declare(strict_types=1);

namespace App\Command;

use App\Resources\ConnectionResource;
use App\Resources\TableResource;
use App\Service\ComposerMetadataExtractor;
use App\Service\DoctrineConfigLoader;
use App\Tools\QueryTool;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
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
                    )
                );

            foreach ($this->doctrineConfigLoader->getConnectionNames() as $connectionName) {
                $builder->addResource(
                    function (string $uri) use ($connectionName): string {
                        $resource = new ConnectionResource($this->doctrineConfigLoader);

                        return $resource($connectionName);
                    },
                    "db://{$connectionName}",
                    $connectionName,
                    ConnectionResource::DESCRIPTION,
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
            $output->getErrorOutput()->writeln(json_encode([
                'error' => $e->getMessage(),
            ]));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
