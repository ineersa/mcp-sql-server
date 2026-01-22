<?php

declare(strict_types=1);

namespace App\Tools;

use App\Service\DoctrineConfigLoader;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

final class QueryTool
{
    public const string NAME = 'query';
    public const string TITLE = 'Query database';
    public const string DESCRIPTION = 'Runs SQL query against chosen database connection. Multiple queries can be executed if separated by semicolons. When selecting data, always default to limiting the results to 50 records (e.g., LIMIT 50) unless it is necessary to retrieve the full dataset.';

    public function __construct(
        private DoctrineConfigLoader $doctrineConfigLoader,
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
                $result = $conn->executeQuery($singleQuery);
                $rows = $result->fetchAllAssociative();
                $count = \count($rows);

                $results[] = [
                    'query' => $singleQuery,
                    'count' => $count,
                    'rows' => $rows,
                ];
            }

            $markdown = $this->formatResultsToMarkdown($results);
            $structuredJson = json_encode($results, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

            return new CallToolResult(
                content: [
                    new TextContent($markdown),
                    new TextContent($structuredJson),
                ],
                isError: false,
                structuredContent: ['results' => $results],
            );
        } catch (\Throwable $e) {
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

        // Get headers from first row
        $headers = array_keys($rows[0]);

        // Header row
        $table = '| '.implode(' | ', $headers)." |\n";

        // Separator row
        $table .= '| '.implode(' | ', array_map(fn () => '---', $headers))." |\n";

        // Data rows
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
}
