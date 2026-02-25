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
}
