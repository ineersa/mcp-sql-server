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

    public function getStoredProcedureDefinition(Connection $connection, string $procedureName): ?string
    {
        try {
            $definition = $connection->executeQuery(
                'SELECT TOP 1 sm.definition
                 FROM sys.procedures p
                 JOIN sys.sql_modules sm ON p.object_id = sm.object_id
                 WHERE p.is_ms_shipped = 0
                   AND p.name = ?
                   AND p.schema_id = SCHEMA_ID(SCHEMA_NAME())',
                [$procedureName]
            )->fetchOne();

            return \is_string($definition) ? $definition : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get stored procedure definition', ['procedure' => $procedureName, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function getFunctionDefinition(Connection $connection, string $functionName): ?string
    {
        try {
            $definition = $connection->executeQuery(
                "SELECT TOP 1 sm.definition
                 FROM sys.objects o
                 JOIN sys.sql_modules sm ON o.object_id = sm.object_id
                 WHERE o.type IN ('FN', 'IF', 'TF')
                   AND o.is_ms_shipped = 0
                   AND o.name = ?
                   AND o.schema_id = SCHEMA_ID(SCHEMA_NAME())",
                [$functionName]
            )->fetchOne();

            return \is_string($definition) ? $definition : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get function definition', ['function' => $functionName, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function getTriggerDefinition(Connection $connection, string $triggerName): ?string
    {
        try {
            $definition = $connection->executeQuery(
                'SELECT TOP 1 sm.definition
                 FROM sys.triggers t
                 JOIN sys.sql_modules sm ON t.object_id = sm.object_id
                 WHERE t.is_ms_shipped = 0
                   AND t.name = ?',
                [$triggerName]
            )->fetchOne();

            return \is_string($definition) ? $definition : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get trigger definition', ['trigger' => $triggerName, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
