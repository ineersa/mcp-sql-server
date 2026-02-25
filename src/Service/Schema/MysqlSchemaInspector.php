<?php

declare(strict_types=1);

namespace App\Service\Schema;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class MysqlSchemaInspector implements DriverSchemaInspectorInterface
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
            $rows = $connection->executeQuery('SHOW PROCEDURE STATUS WHERE Db = DATABASE()')->fetchAllAssociative();

            return $this->extractObjectNames($rows, 'Name');
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get stored procedures', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** @return list<string> */
    public function getFunctions(Connection $connection): array
    {
        try {
            $rows = $connection->executeQuery('SHOW FUNCTION STATUS WHERE Db = DATABASE()')->fetchAllAssociative();

            return $this->extractObjectNames($rows, 'Name');
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get functions', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** @return list<string> */
    public function getTriggers(Connection $connection): array
    {
        try {
            $rows = $connection->executeQuery('SELECT DISTINCT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchAllAssociative();

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
                'SELECT TRIGGER_NAME AS name, EVENT_MANIPULATION AS event, ACTION_TIMING AS timing, ACTION_STATEMENT AS statement
                 FROM information_schema.TRIGGERS
                 WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?',
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
                'SELECT cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS definition
                 FROM information_schema.CHECK_CONSTRAINTS cc
                 JOIN information_schema.TABLE_CONSTRAINTS tc
                     ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
                     AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
                 WHERE tc.CONSTRAINT_TYPE = \'CHECK\'
                   AND tc.CONSTRAINT_SCHEMA = DATABASE()
                   AND tc.TABLE_NAME = ?',
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
            $quotedName = $this->quoteIdentifier($procedureName);
            $row = $connection->executeQuery("SHOW CREATE PROCEDURE {$quotedName}")->fetchAssociative();

            if (!\is_array($row)) {
                return null;
            }

            return $this->extractDefinitionValue($row, ['Create Procedure']);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get stored procedure definition', ['procedure' => $procedureName, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function getFunctionDefinition(Connection $connection, string $functionName): ?string
    {
        try {
            $quotedName = $this->quoteIdentifier($functionName);
            $row = $connection->executeQuery("SHOW CREATE FUNCTION {$quotedName}")->fetchAssociative();

            if (!\is_array($row)) {
                return null;
            }

            return $this->extractDefinitionValue($row, ['Create Function']);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get function definition', ['function' => $functionName, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function getTriggerDefinition(Connection $connection, string $triggerName): ?string
    {
        try {
            $quotedName = $this->quoteIdentifier($triggerName);
            $row = $connection->executeQuery("SHOW CREATE TRIGGER {$quotedName}")->fetchAssociative();

            if (!\is_array($row)) {
                return null;
            }

            return $this->extractDefinitionValue($row, ['SQL Original Statement', 'Statement', 'Create Trigger']);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get trigger definition', ['trigger' => $triggerName, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $preferredKeys
     */
    private function extractDefinitionValue(array $row, array $preferredKeys): ?string
    {
        foreach ($preferredKeys as $key) {
            $value = $row[$key] ?? null;
            if (\is_string($value) && '' !== trim($value)) {
                return $value;
            }
        }

        foreach ($row as $value) {
            if (\is_string($value) && '' !== trim($value)) {
                return $value;
            }
        }

        return null;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
