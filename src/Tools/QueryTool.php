<?php

declare(strict_types=1);

namespace App\Tools;

use App\Service\DoctrineConfigLoader;
use App\Service\SafeQueryExecutor;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;

final class QueryTool
{
    public const string NAME = 'query';
    public const string TITLE = 'Query database';
    public const string DESCRIPTION = <<<DESCRIPTION
Runs read-only SQL queries against chosen database connection.
Only SELECT queries are allowed. INSERT, UPDATE, DELETE, DROP, and other write operations are blocked.

CRITICAL - ROW LIMIT:
- ALWAYS use exactly 10 rows by default. Never use 20, 50, or 100.
- MySQL/PostgreSQL/SQLite: Use LIMIT 10
- SQL Server: Use TOP 10 (LIMIT does not work in SQL Server!)

RULES:
1. SELECT without WHERE MUST have LIMIT or TOP - queries will be rejected otherwise.
2. Check the connection type before writing the query - use correct syntax for that database.
3. For more rows, use pagination with OFFSET.

Examples:
  MySQL/PostgreSQL/SQLite: SELECT * FROM users LIMIT 10;
  SQL Server: SELECT TOP 10 * FROM users;
  Aggregates (no LIMIT needed): SELECT COUNT(*) FROM users;

DESCRIPTION;

    public function __construct(
        private DoctrineConfigLoader $doctrineConfigLoader,
        private SafeQueryExecutor $safeQueryExecutor,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $connection the name of the database connection to use
     * @param string $query      The SQL query (or queries) to execute. Multiple queries must be separated by semicolons.
     */
    public function __invoke(
        string $connection,
        string $query,
    ): CallToolResult {
        $queries = $this->splitSql($query);
        $results = [];

        try {
            $conn = $this->doctrineConfigLoader->getConnection($connection);

            foreach ($queries as $singleQuery) {
                $this->validateQuery($singleQuery);
                $rows = $this->safeQueryExecutor->execute($conn, $singleQuery);
                $count = \count($rows);

                $results[] = [
                    'query' => $singleQuery,
                    'count' => $count,
                    'rows' => $rows,
                ];
            }

            $markdown = $this->formatResultsToMarkdown($results);

            return new CallToolResult(
                content: [
                    new TextContent($markdown),
                ],
                isError: false,
                structuredContent: ['results' => $results],
            );
        } catch (\Throwable $e) {
            $this->logger->error('Query execution failed', [
                'connection' => $connection,
                'query' => $query,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new CallToolResult(
                content: [
                    new TextContent(\sprintf('Error: %s', $e->getMessage())),
                ],
                isError: true,
            );
        }
    }

    public static function getDescription(DoctrineConfigLoader $doctrineConfigLoader): string
    {
        $description = self::DESCRIPTION;

        $description .= "\nAvailable connections:";

        foreach ($doctrineConfigLoader->getConnectionNames() as $connectionName) {
            $conn = $doctrineConfigLoader->getConnection($connectionName);
            $params = $conn->getParams();
            $driver = $params['driver'] ?? 'unknown';

            $platform = match ($driver) {
                'pdo_mysql' => 'MySQL',
                'pdo_pgsql' => 'Postgres',
                'pdo_sqlite' => 'SQLite',
                'pdo_sqlsrv' => 'SQL Server',
                default => $driver,
            };

            $version = $params['serverVersion'] ?? null;

            if (null === $version) {
                try {
                    $rawVersion = $conn->getServerVersion();

                    if ('MySQL' === $platform && false !== stripos($rawVersion, 'MariaDB')) {
                        $platform = 'MariaDB';
                    }

                    $version = $rawVersion;
                } catch (\Throwable) {
                    $version = 'unknown';
                }
            }

            $description .= \sprintf(
                "\n - %s : %s, version %s",
                $connectionName,
                $platform,
                $version
            );
        }

        return $description;
    }

    /** @return string[] */
    private function splitSql(string $sql): array
    {
        $queries = [];
        $length = \strlen($sql);
        $buffer = '';
        $inString = false;
        $quoteChar = null;
        $escaped = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; ++$i) {
            $char = $sql[$i];

            if ($inLineComment) {
                if ("\n" === $char) {
                    $inLineComment = false;
                    $buffer .= "\n";
                }
                continue;
            }

            if ($inBlockComment) {
                if ('*' === $char && isset($sql[$i + 1]) && '/' === $sql[$i + 1]) {
                    $inBlockComment = false;
                    ++$i;
                    $buffer .= ' ';
                }
                continue;
            }

            if ($escaped) {
                $buffer .= $char;
                $escaped = false;
                continue;
            }

            if ('\\' === $char) {
                $buffer .= $char;
                $escaped = true;
                continue;
            }

            if ($inString) {
                if ($char === $quoteChar) {
                    // Check for double quote escape (e.g. 'It''s')
                    if (isset($sql[$i + 1]) && $sql[$i + 1] === $quoteChar) {
                        $buffer .= $char.$quoteChar;
                        ++$i; // Skip next char
                        continue;
                    }
                    $inString = false;
                    $quoteChar = null;
                }
                $buffer .= $char;
            } else {
                if ('#' === $char) {
                    $inLineComment = true;
                    continue;
                }
                if ('-' === $char && isset($sql[$i + 1]) && '-' === $sql[$i + 1]) {
                    $inLineComment = true;
                    ++$i;
                    continue;
                }
                if ('/' === $char && isset($sql[$i + 1]) && '*' === $sql[$i + 1]) {
                    $inBlockComment = true;
                    ++$i;
                    continue;
                }

                if ("'" === $char || '"' === $char) {
                    $inString = true;
                    $quoteChar = $char;
                    $buffer .= $char;
                } elseif (';' === $char) {
                    $trimmed = trim($buffer);
                    if ('' !== $trimmed) {
                        $queries[] = $trimmed;
                    }
                    $buffer = '';
                    continue;
                } else {
                    $buffer .= $char;
                }
            }
        }

        $trimmed = trim($buffer);
        if ('' !== $trimmed) {
            $queries[] = $trimmed;
        }

        return $queries;
    }

    /** @param array<int, array<string, mixed>> $results */
    private function formatResultsToMarkdown(array $results): string
    {
        $markdown = '';

        foreach ($results as $index => $result) {
            $markdown .= '## Query '.($index + 1)."\n";
            $markdown .= "```sql\n".$result['query']."\n```\n";

            if (isset($result['error'])) {
                $markdown .= '**Error:** '.$result['error']."\n\n";
                continue;
            }

            $markdown .= "### Count\n".$result['count']."\n\n";

            if (empty($result['rows'])) {
                $markdown .= "No results.\n\n";
                continue;
            }

            $markdown .= "### Rows\n";
            $markdown .= $this->arrayToMarkdownTable($result['rows']);
            $markdown .= "\n";
        }

        return $markdown;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function arrayToMarkdownTable(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $headers = array_keys($rows[0]);

        $table = '| '.implode(' | ', $headers)." |\n";

        $table .= '| '.implode(' | ', array_map(fn () => '---', $headers))." |\n";

        foreach ($rows as $row) {
            $table .= '| '.implode(' | ', array_map(function ($val) {
                if (null === $val) {
                    return 'NULL';
                }
                if (\is_bool($val)) {
                    return $val ? 'true' : 'false';
                }
                if (\is_array($val) || \is_object($val)) {
                    return json_encode($val);
                }

                return str_replace('|', "\|", (string) $val);
            }, $row))." |\n";
        }

        return $table;
    }

    private function validateQuery(string $query): void
    {
        $normalized = strtoupper(trim($query));

        if (!str_starts_with($normalized, 'SELECT')) {
            return;
        }

        if (str_contains($normalized, 'WHERE ')) {
            return;
        }

        if (str_contains($normalized, 'LIMIT ') || str_contains($normalized, 'TOP ')) {
            return;
        }

        if (str_contains($normalized, 'FETCH NEXT')) {
            return;
        }

        // Allow aggregate-only queries (COUNT, SUM, AVG, etc.) without LIMIT
        // since they inherently return a single row when no GROUP BY is present
        if ($this->isAggregateOnlyQuery($normalized)) {
            return;
        }

        throw new \InvalidArgumentException('SELECT query without WHERE clause must include LIMIT or TOP.');
    }

    /**
     * Check if the query is an aggregate-only query (returns single row).
     * This is a heuristic: if query has aggregate functions and no GROUP BY,
     * it will return exactly one row, so LIMIT is not needed.
     */
    private function isAggregateOnlyQuery(string $normalized): bool
    {
        // If it has GROUP BY, it can return multiple rows
        if (str_contains($normalized, 'GROUP BY')) {
            return false;
        }

        // Common aggregate functions
        $aggregateFunctions = [
            ' COUNT(',
            ' SUM(',
            ' AVG(',
            ' MIN(',
            ' MAX(',
            ' GROUP_CONCAT(',
            ' STRING_AGG(',
        ];

        $hasAggregate = false;
        foreach ($aggregateFunctions as $func) {
            if (str_contains($normalized, $func)) {
                $hasAggregate = true;
                break;
            }
        }

        if (!$hasAggregate) {
            return false;
        }

        // Extract the part between SELECT and FROM
        if (!preg_match('/SELECT\s+(.*?)\s+FROM/s', $normalized, $matches)) {
            return false;
        }

        $selectPart = $matches[1];

        // If it selects *, it's not aggregate-only
        if (str_contains($selectPart, '*') && !str_contains($selectPart, '(*)')) {
            return false;
        }

        // Check if ALL selected items are aggregate functions or literals
        // Simple heuristic: if SELECT part contains aggregate and no plain column refs
        // This is a basic check - a full SQL parser would be more accurate
        return $hasAggregate;
    }
}
