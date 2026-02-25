<?php

declare(strict_types=1);

namespace App\Service\Schema;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class SqlServerSchemaInspector implements DriverSchemaInspectorInterface
{
    use SchemaObjectNameExtractorTrait;

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /** @return list<string> */
    public function getStoredProcedures(Connection $connection): array
    {
        try {
            $rows = $connection->executeQuery('SELECT name FROM sys.procedures WHERE is_ms_shipped = 0')->fetchAllAssociative();

            return $this->extractObjectNames($rows, 'name');
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get stored procedures', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** @return list<string> */
    public function getFunctions(Connection $connection): array
    {
        try {
            $rows = $connection->executeQuery("\n                SELECT name\n                FROM sys.objects\n                WHERE type IN ('FN', 'IF', 'TF') AND is_ms_shipped = 0\n            ")->fetchAllAssociative();

            return $this->extractObjectNames($rows, 'name');
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get functions', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** @return list<string> */
    public function getTriggers(Connection $connection): array
    {
        try {
            $rows = $connection->executeQuery('SELECT DISTINCT t.name FROM sys.triggers t JOIN sys.tables tbl ON t.parent_id = tbl.object_id WHERE t.is_ms_shipped = 0')->fetchAllAssociative();

            return $this->extractObjectNames($rows, 'name');
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get triggers list', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getTableTriggers(Connection $connection, string $tableName): array
    {
        try {
            return $connection->executeQuery(
                'SELECT t.name, te.type_desc AS event
                 FROM sys.triggers t
                 JOIN sys.trigger_events te ON t.object_id = te.object_id
                 JOIN sys.tables tbl ON t.parent_id = tbl.object_id
                 WHERE tbl.name = ?',
                [$tableName]
            )->fetchAllAssociative();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get triggers', ['table' => $tableName, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getTableCheckConstraints(Connection $connection, string $tableName): array
    {
        try {
            return $connection->executeQuery(
                'SELECT cc.name, cc.definition
                 FROM sys.check_constraints cc
                 JOIN sys.tables t ON cc.parent_object_id = t.object_id
                 WHERE t.name = ?',
                [$tableName]
            )->fetchAllAssociative();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get check constraints', ['table' => $tableName, 'error' => $e->getMessage()]);

            return [];
        }
    }
}
