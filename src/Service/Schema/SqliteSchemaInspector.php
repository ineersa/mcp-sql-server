<?php

declare(strict_types=1);

namespace App\Service\Schema;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class SqliteSchemaInspector implements DriverSchemaInspectorInterface
{
    use SchemaObjectNameExtractorTrait;

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

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
        try {
            $rows = $connection->executeQuery("SELECT name FROM sqlite_master WHERE type = 'trigger'")->fetchAllAssociative();

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
                "SELECT name, sql AS statement FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?",
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
        try {
            $definition = $connection->executeQuery(
                "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?",
                [$triggerName]
            )->fetchOne();

            return \is_string($definition) ? $definition : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get trigger definition', ['trigger' => $triggerName, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
