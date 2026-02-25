<?php

declare(strict_types=1);

namespace App\Service\Schema;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class PostgreSqlSchemaInspector implements DriverSchemaInspectorInterface
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
            $rows = $connection->executeQuery("\n                SELECT p.proname AS routine_name\n                FROM pg_proc p\n                JOIN pg_namespace n ON p.pronamespace = n.oid\n                WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')\n                AND p.prokind = 'p'\n            ")->fetchAllAssociative();

            return $this->extractObjectNames($rows, 'routine_name');
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get stored procedures', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** @return list<string> */
    public function getFunctions(Connection $connection): array
    {
        try {
            $rows = $connection->executeQuery("\n                SELECT p.proname AS routine_name\n                FROM pg_proc p\n                JOIN pg_namespace n ON p.pronamespace = n.oid\n                WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')\n                AND p.prokind = 'f'\n            ")->fetchAllAssociative();

            return $this->extractObjectNames($rows, 'routine_name');
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get functions', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** @return list<string> */
    public function getTriggers(Connection $connection): array
    {
        try {
            $rows = $connection->executeQuery('SELECT DISTINCT trigger_name AS name FROM information_schema.triggers WHERE trigger_schema = current_schema()')->fetchAllAssociative();

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
                'SELECT trigger_name AS name, event_manipulation AS event, action_timing AS timing, action_statement AS statement
                 FROM information_schema.triggers
                 WHERE trigger_schema = current_schema() AND event_object_table = ?',
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
                "SELECT conname AS name, pg_get_constraintdef(oid) AS definition\n                 FROM pg_constraint\n                 WHERE contype = 'c' AND conrelid = ?::regclass",
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
                "SELECT pg_get_functiondef(p.oid) AS definition
                 FROM pg_proc p
                 JOIN pg_namespace n ON p.pronamespace = n.oid
                 WHERE n.nspname = current_schema()
                   AND p.prokind = 'p'
                   AND p.proname = ?
                 ORDER BY p.oid
                 LIMIT 1",
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
                "SELECT pg_get_functiondef(p.oid) AS definition
                 FROM pg_proc p
                 JOIN pg_namespace n ON p.pronamespace = n.oid
                 WHERE n.nspname = current_schema()
                   AND p.prokind = 'f'
                   AND p.proname = ?
                 ORDER BY p.oid
                 LIMIT 1",
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
                'SELECT pg_get_triggerdef(t.oid, true) AS definition
                 FROM pg_trigger t
                 JOIN pg_class c ON c.oid = t.tgrelid
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE NOT t.tgisinternal
                   AND n.nspname = current_schema()
                   AND t.tgname = ?
                 ORDER BY t.oid
                 LIMIT 1',
                [$triggerName]
            )->fetchOne();

            return \is_string($definition) ? $definition : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get trigger definition', ['trigger' => $triggerName, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
