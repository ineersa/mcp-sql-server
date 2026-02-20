<?php

declare(strict_types=1);

namespace App\Tools;

use App\Exception\ToolUsageError;
use App\Service\DatabaseSchemaService;
use App\Service\DoctrineConfigLoader;
use Doctrine\DBAL\Exception as DbalException;
use HelgeSverre\Toon\Toon;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;

final class SchemaTool
{
    public const string NAME = 'schema';
    public const string TITLE = 'Database Schema structure';
    public const string DESCRIPTION = <<<DESCRIPTION
Get the exact structure of tables, views, and routines for a given database connection.
Use this to understand foreign keys, nullable fields, and exact column types for writing precision SQL queries.
Will return the schema matches as a Toon-encoded text.

Params:
- database (string): Connection name (required)
- filter (string): Filter objects by name substring match (required)
- include_views (bool): Defaults to false
- include_routines (bool): Defaults to false
DESCRIPTION;

    public function __construct(
        private DatabaseSchemaService $databaseSchemaService,
        private DoctrineConfigLoader $doctrineConfigLoader,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        string $connection,
        string $filter,
        bool $includeViews = false,
        bool $includeRoutines = false,
    ): CallToolResult {
        try {
            $conn = $this->doctrineConfigLoader->getConnection($connection);

            $schema = $this->databaseSchemaService->getSchemaStructure(
                $conn,
                $this->doctrineConfigLoader->getConnectionType($connection) ?? 'unknown',
                $filter,
                $includeViews,
                $includeRoutines,
            );

            return new CallToolResult(
                content: [
                    new TextContent(Toon::encode($schema)),
                ],
                isError: false,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Schema extraction failed', [
                'connection' => $connection,
                'filter' => $filter,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->buildErrorResult($e);
        }
    }

    private function buildErrorResult(\Throwable $error): CallToolResult
    {
        $toolError = $this->mapThrowableToToolUsageError($error);

        return new CallToolResult(
            content: [
                new TextContent(Toon::encode([
                    'error' => $toolError->getMessage(),
                    'hint' => $toolError->getHint(),
                    'retryable' => $toolError->isRetryable(),
                ])),
            ],
            isError: true,
        );
    }

    private function mapThrowableToToolUsageError(\Throwable $error): ToolUsageError
    {
        if ($error instanceof ToolUsageError) {
            return $error;
        }

        if ($error instanceof DbalException) {
            return new ToolUsageError(
                message: $error->getMessage(),
                hint: 'Failed to extract schema from the database. Verify connection health and retry.',
                retryable: true,
                previous: $error,
            );
        }

        return new ToolUsageError(
            message: $error->getMessage(),
            hint: 'Temporary internal failure in the schema tool. Retry the same call once.',
            retryable: true,
            previous: $error,
        );
    }
}
