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
    public const string DESCRIPTION = 'Runs SQL query against chosen database connection.';

    public function __construct(
        private DoctrineConfigLoader $doctrineConfigLoader,
    ) {
    }

    public function __invoke(
        string $connection,
        string $query,
    ): CallToolResult {
        try {
            $conn = $this->doctrineConfigLoader->getConnection($connection);
            $result = $conn->executeQuery($query);
            $rows = $result->fetchAllAssociative();

            return new CallToolResult(
                content: [
                    new TextContent(
                        text: json_encode($rows, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
                    ),
                ],
                isError: false,
            );
        } catch (\Throwable $e) {
            return new CallToolResult(
                content: [
                    new TextContent(
                        text: \sprintf('Error executing query: %s', $e->getMessage()),
                    ),
                ],
                isError: true,
            );
        }
    }

    public static function getDescription(DoctrineConfigLoader $doctrineConfigLoader): string
    {
        $description = self::DESCRIPTION;

        $description .= 'Available connections:';

        foreach ($doctrineConfigLoader->getConnectionNames() as $connectionName) {
            $conn = $doctrineConfigLoader->getConnection($connectionName);
            $params = $conn->getParams();
            $description .= \sprintf(
                "\n%s: %s (host: %s, database: %s)",
                $connectionName,
                $params['driver'] ?? 'unknown',
                $params['host'] ?? 'unknown',
                $params['dbname'] ?? 'unknown',
            );
        }

        return $description;
    }
}
