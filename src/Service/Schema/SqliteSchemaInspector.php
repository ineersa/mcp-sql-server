<?php

declare(strict_types=1);

namespace App\Service\Schema;

use Doctrine\DBAL\Connection;

final class SqliteSchemaInspector implements DriverSchemaInspectorInterface
{
    use SchemaObjectNameExtractorTrait;

    /** @return list<string> */
    public function getStoredProcedures(Connection $connection): array
    {
        return [];
    }

    /** @return list<string> */
    public function getFunctions(Connection $connection): array
    {
        return [];
    }

    /** @return list<string> */
    public function getTriggers(Connection $connection): array
    {
        $rows = $connection->executeQuery("SELECT name FROM sqlite_master WHERE type = 'trigger'")->fetchAllAssociative();

        return $this->extractObjectNames($rows, 'name');
    }

    /** @return array<int, array<string, mixed>> */
    public function getTableTriggers(Connection $connection, string $tableName): array
    {
        return $connection->executeQuery(
            "SELECT name, sql AS statement FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?",
            [$tableName]
        )->fetchAllAssociative();
    }

    /** @return array<int, array<string, mixed>> */
    public function getTableCheckConstraints(Connection $connection, string $tableName): array
    {
        return [];
    }

    public function getStoredProcedureDefinition(Connection $connection, string $procedureName): ?string
    {
        return null;
    }

    public function getFunctionDefinition(Connection $connection, string $functionName): ?string
    {
        return null;
    }

    public function getTriggerDefinition(Connection $connection, string $triggerName): ?string
    {
        $definition = $connection->executeQuery(
            "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?",
            [$triggerName]
        )->fetchOne();

        return \is_string($definition) ? $definition : null;
    }
}
