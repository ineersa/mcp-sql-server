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
    public const string DETAIL_SUMMARY = 'summary';
    public const string DETAIL_COLUMNS = 'columns';
    public const string DETAIL_FULL = 'full';
    public const string MATCH_MODE_CONTAINS = 'contains';
    public const string MATCH_MODE_PREFIX = 'prefix';
    public const string MATCH_MODE_EXACT = 'exact';
    public const string MATCH_MODE_GLOB = 'glob';

    public const string DESCRIPTION = <<<DESCRIPTION
Inspect schema for a database connection.

Use detail="summary" (default) to list matching object names.
Use detail="columns" to list matching tables with column types.
Use detail="full" to return full structures (columns, indexes, foreign keys, triggers, check constraints).

Filter note:
- filter is required and matches object names (tables, views, procedures, functions, sequences).
- Use filter="" to include all object names.

Recommended flow:
1. Call with detail="summary" and empty filter to discover names.
2. Call with detail="columns" to inspect column names and types.
3. Call again with a refined filter and detail="full" for exact structure.

Routine note:
- includeRoutines=true returns stored_procedures, functions, and sequences.
- In PostgreSQL, many routines are exposed as functions (not procedures).

Metadata note:
- For direct information_schema/system catalog queries, use real database/schema names not MCP connection aliases.

If detail="columns" or detail="full" output is too large, the tool returns ToolUsageError and asks for a narrower filter.
DESCRIPTION;

    private const int MAX_OUTPUT_TOKENS = 2500;

    /** @var list<string> */
    private const array ALLOWED_DETAILS = [
        self::DETAIL_SUMMARY,
        self::DETAIL_COLUMNS,
        self::DETAIL_FULL,
    ];

    /** @var list<string> */
    private const array ALLOWED_MATCH_MODES = [
        self::MATCH_MODE_CONTAINS,
        self::MATCH_MODE_PREFIX,
        self::MATCH_MODE_EXACT,
        self::MATCH_MODE_GLOB,
    ];

    public function __construct(
        private DatabaseSchemaService $databaseSchemaService,
        private DoctrineConfigLoader $doctrineConfigLoader,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $connection      Connection name
     * @param string $filter          Required object-name filter; empty string means all
     * @param string $detail          Schema detail level: summary, columns, or full
     * @param string $matchMode       Filter matching mode: contains, prefix, exact, glob
     * @param bool   $includeViews    Include views in response
     * @param bool   $includeRoutines Include procedures, functions, and sequences in response
     */
    public function __invoke(
        string $connection,
        string $filter,
        string $detail = self::DETAIL_SUMMARY,
        string $matchMode = self::MATCH_MODE_CONTAINS,
        bool $includeViews = false,
        bool $includeRoutines = false,
    ): CallToolResult {
        try {
            $detail = $this->normalizeDetail($detail);
            $matchMode = $this->normalizeMatchMode($matchMode);

            $conn = $this->doctrineConfigLoader->getConnection($connection);

            $schema = $this->databaseSchemaService->getSchemaStructure(
                $conn,
                $this->doctrineConfigLoader->getConnectionType($connection) ?? 'unknown',
                $filter,
                $detail,
                $matchMode,
                $includeViews,
                $includeRoutines,
            );

            $encodedSchema = Toon::encode($schema);

            if (self::DETAIL_SUMMARY !== $detail) {
                $estimatedTokens = $this->estimateTokenCount($encodedSchema);

                if ($estimatedTokens >= self::MAX_OUTPUT_TOKENS && !$this->isSingleTableResult($schema)) {
                    throw new ToolUsageError(message: \sprintf('Schema output is too large (estimated %d tokens).', $estimatedTokens), hint: 'Use a narrower filter or switch to detail="summary". You can also refine matching with matchMode (contains, prefix, exact, glob).', retryable: false);
                }
            }

            return new CallToolResult(
                content: [
                    new TextContent($encodedSchema),
                ],
                isError: false,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Schema extraction failed', [
                'connection' => $connection,
                'filter' => $filter,
                'detail' => $detail,
                'match_mode' => $matchMode,
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

    private function normalizeDetail(string $detail): string
    {
        $normalized = strtolower(trim($detail));

        if (!\in_array($normalized, self::ALLOWED_DETAILS, true)) {
            throw new ToolUsageError(message: \sprintf('Invalid detail value "%s".', $detail), hint: 'Use detail="summary", detail="columns", or detail="full".', retryable: false);
        }

        return $normalized;
    }

    private function normalizeMatchMode(string $matchMode): string
    {
        $normalized = strtolower(trim($matchMode));

        if (!\in_array($normalized, self::ALLOWED_MATCH_MODES, true)) {
            throw new ToolUsageError(message: \sprintf('Invalid matchMode value "%s".', $matchMode), hint: 'Use one of: contains, prefix, exact, glob.', retryable: false);
        }

        return $normalized;
    }

    /** @param array<string, mixed> $schema */
    private function isSingleTableResult(array $schema): bool
    {
        if (!isset($schema['tables']) || !\is_array($schema['tables'])) {
            return false;
        }

        return 1 === \count($schema['tables']);
    }

    private function estimateTokenCount(string $payload): int
    {
        return (int) ceil(\strlen($payload) / 3.5);
    }
}
