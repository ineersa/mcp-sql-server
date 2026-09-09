<?php

declare(strict_types=1);

namespace App\Service\Schema;

use Doctrine\DBAL\Connection;

final class SqlServerSchemaInspector implements DriverSchemaInspectorInterface
{
    use SchemaObjectNameExtractorTrait;

    /** @return list<string> */
    public function getStoredProcedures(Connection $connection): array
    {
        $rows = $connection->executeQuery('SELECT name FROM sys.procedures WHERE is_ms_shipped = 0')->fetchAllAssociative();

        return $this->extractObjectNames($rows, 'name');
    }

    /** @return list<string> */
    public function getFunctions(Connection $connection): array
    {
        $rows = $connection->executeQuery("\n                SELECT name\n                FROM sys.objects\n                WHERE type IN ('FN', 'IF', 'TF') AND is_ms_shipped = 0\n            ")->fetchAllAssociative();

        return $this->extractObjectNames($rows, 'name');
    }

    /** @return list<string> */
    public function getTriggers(Connection $connection): array
    {
        $rows = $connection->executeQuery('SELECT DISTINCT t.name FROM sys.triggers t JOIN sys.tables tbl ON t.parent_id = tbl.object_id WHERE t.is_ms_shipped = 0')->fetchAllAssociative();

        return $this->extractObjectNames($rows, 'name');
    }

    /** @return array<int, array<string, mixed>> */
    public function getTableTriggers(Connection $connection, string $tableName): array
    {
        return $connection->executeQuery(
            'SELECT t.name, te.type_desc AS event
                 FROM sys.triggers t
                 JOIN sys.trigger_events te ON t.object_id = te.object_id
                 JOIN sys.tables tbl ON t.parent_id = tbl.object_id
                 WHERE tbl.name = ?',
            [$tableName]
        )->fetchAllAssociative();
    }

    /** @return array<int, array<string, mixed>> */
    public function getTableCheckConstraints(Connection $connection, string $tableName): array
    {
        return $connection->executeQuery(
            'SELECT cc.name, cc.definition
                 FROM sys.check_constraints cc
                 JOIN sys.tables t ON cc.parent_object_id = t.object_id
                 WHERE t.name = ?',
            [$tableName]
        )->fetchAllAssociative();
    }

    public function getStoredProcedureDefinition(Connection $connection, string $procedureName): ?string
    {
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
    }

    public function getFunctionDefinition(Connection $connection, string $functionName): ?string
    {
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
    }

    public function getTriggerDefinition(Connection $connection, string $triggerName): ?string
    {
        $definition = $connection->executeQuery(
            'SELECT TOP 1 sm.definition
                 FROM sys.triggers t
                 JOIN sys.sql_modules sm ON t.object_id = sm.object_id
                 WHERE t.is_ms_shipped = 0
                   AND t.name = ?',
            [$triggerName]
        )->fetchOne();

        return \is_string($definition) ? $definition : null;
    }
}
