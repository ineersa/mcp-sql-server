<?php

declare(strict_types=1);

namespace App\Tools;

use App\Service\DoctrineConfigLoader;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\TextContent;

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
}
