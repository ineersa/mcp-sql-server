<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Index\IndexType;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class DatabaseSchemaService
{
    private const string DETAIL_SUMMARY = 'summary';
    private const string DETAIL_COLUMNS = 'columns';
    private const string DETAIL_FULL = 'full';

    private const string MATCH_MODE_CONTAINS = 'contains';
    private const string MATCH_MODE_PREFIX = 'prefix';
    private const string MATCH_MODE_EXACT = 'exact';
    private const string MATCH_MODE_GLOB = 'glob';

    public function __construct(
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /** @return array<string, mixed> */
    public function getSchemaStructure(
        Connection $conn,
        string $engineName,
        string $filter,
        string $detail,
        string $matchMode,
        bool $includeViews,
        bool $includeRoutines,
    ): array {
        $cacheKey = \sprintf(
            'database_schema_%s_%s_%s_%s_%d_%d',
            md5(serialize($conn->getParams())),
            md5($filter),
            md5($detail),
            md5($matchMode),
            (int) $includeViews,
            (int) $includeRoutines
        );

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($conn, $engineName, $filter, $detail, $matchMode, $includeViews, $includeRoutines) {
            $item->expiresAfter(60);

            return $this->buildSchemaStructure($conn, $engineName, $filter, $detail, $matchMode, $includeViews, $includeRoutines);
        });
    }

    /**
     * @return list<string>
     */
    public function getViewsList(Connection $conn): array
    {
        $views = [];
        foreach ($conn->createSchemaManager()->introspectViews() as $view) {
            $views[] = $view->getObjectName()->toString();
        }

        return $views;
    }

    /**
     * @return array{stored_procedures: list<string>, functions: list<string>}
     */
    public function getRoutinesList(Connection $conn): array
    {
        return [
            'stored_procedures' => $this->getStoredProcedures($conn),
            'functions' => $this->getFunctions($conn),
        ];
    }

    /** @return array<string, mixed> */
    private function buildSchemaStructure(
        Connection $conn,
        string $engineName,
        string $filter,
        string $detail,
        string $matchMode,
        bool $includeViews,
        bool $includeRoutines,
    ): array {
        $normalizedDetail = $this->normalizeDetail($detail);
        $normalizedMatchMode = $this->normalizeMatchMode($matchMode);

        $result = [
            'engine' => $engineName,
            'detail' => $normalizedDetail,
            'match_mode' => $normalizedMatchMode,
            'tables' => match ($normalizedDetail) {
                self::DETAIL_SUMMARY => $this->getAllTableNames($conn, $filter, $normalizedMatchMode),
                self::DETAIL_COLUMNS => $this->getAllTableColumnsStructure($conn, $filter, $normalizedMatchMode),
                default => $this->getAllTablesStructure($conn, $filter, $normalizedMatchMode),
            },
        ];

        if ($includeViews) {
            $result['views'] = self::DETAIL_FULL === $normalizedDetail
                ? $this->getViewsStructure($conn, $filter, $normalizedMatchMode)
                : $this->getViewNames($conn, $filter, $normalizedMatchMode);
        }

        if ($includeRoutines) {
            $result['routines'] = [
                'stored_procedures' => self::DETAIL_FULL === $normalizedDetail
                    ? $this->getStoredProceduresStructure($conn, $filter, $normalizedMatchMode)
                    : $this->getStoredProceduresNames($conn, $filter, $normalizedMatchMode),
                'functions' => self::DETAIL_FULL === $normalizedDetail
                    ? $this->getFunctionsStructure($conn, $filter, $normalizedMatchMode)
                    : $this->getFunctionsNames($conn, $filter, $normalizedMatchMode),
                'sequences' => self::DETAIL_FULL === $normalizedDetail
                    ? $this->getSequencesStructure($conn, $filter, $normalizedMatchMode)
                    : $this->getSequencesNames($conn, $filter, $normalizedMatchMode),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getAllTablesStructure(Connection $conn, string $filter, string $matchMode): array
    {
        $schemaManager = $conn->createSchemaManager();
        $structures = [];

        foreach ($schemaManager->introspectTables() as $table) {
            $tableName = $table->getObjectName()->toString();

            if (!$this->matchesFilter($tableName, $filter, $matchMode)) {
                continue;
            }

            $columns = [];
            foreach ($table->getColumns() as $column) {
                $typeClass = \get_class($column->getType());
                $typeParts = explode('\\', $typeClass);
                $detail = [
                    'type' => str_replace('Type', '', end($typeParts)),
                    'nullable' => !$column->getNotnull(),
                    'default' => $column->getDefault(),
                    'auto_increment' => $column->getAutoincrement(),
                ];

                $comment = $column->getComment();
                if (null !== $comment && '' !== $comment) {
                    $detail['comment'] = $comment;
                }

                $columns[$column->getName()] = $detail;
            }

            $indexes = [];
            foreach ($table->getIndexes() as $index) {
                $indexName = $index->getObjectName()->toString();
                $indexType = $index->getType();
                $indexes[$indexName] = [
                    'columns' => array_map(
                        static fn ($col) => $col->getColumnName()->toString(),
                        $index->getIndexedColumns()
                    ),
                    'is_unique' => IndexType::UNIQUE === $indexType,
                    'is_primary' => $index->isPrimary(),
                ];
            }

            $foreignKeys = [];
            foreach ($table->getForeignKeys() as $fk) {
                $fkName = $fk->getObjectName()?->toString() ?? '';
                $foreignKeys[$fkName] = [
                    'local_columns' => array_map(
                        static fn ($col) => $col->toString(),
                        $fk->getReferencingColumnNames()
                    ),
                    'foreign_table' => $fk->getReferencedTableName()->toString(),
                    'foreign_columns' => array_map(
                        static fn ($col) => $col->toString(),
                        $fk->getReferencedColumnNames()
                    ),
                ];
            }

            // getObjectName()->toString() may return a quoted identifier like "users"
            // Strip surrounding quotes for use in raw SQL queries
            $rawTableName = trim($tableName, '"\' ');

            $structures[$tableName] = [
                'columns' => $columns,
                'indexes' => $indexes,
                'foreign_keys' => $foreignKeys,
                'triggers' => $this->getTableTriggers($conn, $rawTableName),
                'check_constraints' => $this->getTableCheckConstraints($conn, $rawTableName),
            ];
        }

        return $structures;
    }

    /** @return list<string> */
    private function getAllTableNames(Connection $conn, string $filter, string $matchMode): array
    {
        $tableNames = [];

        foreach ($conn->createSchemaManager()->introspectTables() as $table) {
            $tableName = $table->getObjectName()->toString();
            if (!$this->matchesFilter($tableName, $filter, $matchMode)) {
                continue;
            }

            $tableNames[] = $tableName;
        }

        return $tableNames;
    }

    /** @return array<string, array{columns: array<string, string>}> */
    private function getAllTableColumnsStructure(Connection $conn, string $filter, string $matchMode): array
    {
        $tables = [];

        foreach ($conn->createSchemaManager()->introspectTables() as $table) {
            $tableName = $table->getObjectName()->toString();

            if (!$this->matchesFilter($tableName, $filter, $matchMode)) {
                continue;
            }

            $columns = [];
            foreach ($table->getColumns() as $column) {
                $typeClass = \get_class($column->getType());
                $typeParts = explode('\\', $typeClass);
                $columns[$column->getName()] = str_replace('Type', '', end($typeParts));
            }

            $tables[$tableName] = [
                'columns' => $columns,
            ];
        }

        return $tables;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getViewsStructure(Connection $conn, string $filter, string $matchMode): array
    {
        $views = [];

        foreach ($conn->createSchemaManager()->introspectViews() as $view) {
            $viewName = $view->getObjectName()->toString();

            if (!$this->matchesFilter($viewName, $filter, $matchMode)) {
                continue;
            }

            $views[$viewName] = [
                'sql' => $view->getSql(),
            ];
        }

        return $views;
    }

    /** @return list<string> */
    private function getViewNames(Connection $conn, string $filter, string $matchMode): array
    {
        $viewNames = [];

        foreach ($conn->createSchemaManager()->introspectViews() as $view) {
            $viewName = $view->getObjectName()->toString();
            if (!$this->matchesFilter($viewName, $filter, $matchMode)) {
                continue;
            }

            $viewNames[] = $viewName;
        }

        return $viewNames;
    }

    /**
     * @return list<string>
     */
    private function getStoredProcedures(Connection $conn): array
    {
        $driver = $this->detectDriver($conn);

        try {
            if (str_contains($driver, 'mysql') || str_contains($driver, 'mariadb')) {
                $stmt = $conn->executeQuery('SHOW PROCEDURE STATUS WHERE Db = DATABASE()');
                $rows = $stmt->fetchAllAssociative();

                return array_map(static fn ($row) => $row['Name'], $rows);
            }
            if (str_contains($driver, 'postgres')) {
                $stmt = $conn->executeQuery("
                    SELECT p.proname AS routine_name
                    FROM pg_proc p
                    JOIN pg_namespace n ON p.pronamespace = n.oid
                    WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
                    AND p.prokind = 'p'
                ");
                $rows = $stmt->fetchAllAssociative();

                return array_map(static fn ($row) => $row['routine_name'], $rows);
            }
            if (str_contains($driver, 'sqlite')) {
                return [];
            }
            if (str_contains($driver, 'sqlsrv') || str_contains($driver, 'sqlserver')) {
                $stmt = $conn->executeQuery('
                    SELECT name
                    FROM sys.procedures
                    WHERE is_ms_shipped = 0
                ');
                $rows = $stmt->fetchAllAssociative();

                return array_map(static fn ($row) => $row['name'], $rows);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get stored procedures', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getStoredProceduresStructure(Connection $conn, string $filter, string $matchMode): array
    {
        $structures = [];

        foreach ($this->getStoredProcedures($conn) as $proc) {
            if (!$this->matchesFilter($proc, $filter, $matchMode)) {
                continue;
            }
            $structures[$proc] = ['type' => 'procedure'];
        }

        return $structures;
    }

    /** @return list<string> */
    private function getStoredProceduresNames(Connection $conn, string $filter, string $matchMode): array
    {
        $names = [];

        foreach ($this->getStoredProcedures($conn) as $procedureName) {
            if (!$this->matchesFilter($procedureName, $filter, $matchMode)) {
                continue;
            }

            $names[] = $procedureName;
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function getFunctions(Connection $conn): array
    {
        $driver = $this->detectDriver($conn);

        try {
            if (str_contains($driver, 'mysql') || str_contains($driver, 'mariadb')) {
                $stmt = $conn->executeQuery('SHOW FUNCTION STATUS WHERE Db = DATABASE()');
                $rows = $stmt->fetchAllAssociative();

                return array_map(static fn ($row) => $row['Name'], $rows);
            }
            if (str_contains($driver, 'postgres')) {
                $stmt = $conn->executeQuery("
                    SELECT p.proname AS routine_name
                    FROM pg_proc p
                    JOIN pg_namespace n ON p.pronamespace = n.oid
                    WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
                    AND p.prokind = 'f'
                ");
                $rows = $stmt->fetchAllAssociative();

                return array_map(static fn ($row) => $row['routine_name'], $rows);
            }
            if (str_contains($driver, 'sqlite')) {
                return [];
            }
            if (str_contains($driver, 'sqlsrv') || str_contains($driver, 'sqlserver')) {
                $stmt = $conn->executeQuery("
                    SELECT name
                    FROM sys.objects
                    WHERE type IN ('FN', 'IF', 'TF') AND is_ms_shipped = 0
                ");
                $rows = $stmt->fetchAllAssociative();

                return array_map(static fn ($row) => $row['name'], $rows);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get functions', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getFunctionsStructure(Connection $conn, string $filter, string $matchMode): array
    {
        $structures = [];

        foreach ($this->getFunctions($conn) as $func) {
            if (!$this->matchesFilter($func, $filter, $matchMode)) {
                continue;
            }
            $structures[$func] = ['type' => 'function'];
        }

        return $structures;
    }

    /** @return list<string> */
    private function getFunctionsNames(Connection $conn, string $filter, string $matchMode): array
    {
        $names = [];

        foreach ($this->getFunctions($conn) as $functionName) {
            if (!$this->matchesFilter($functionName, $filter, $matchMode)) {
                continue;
            }

            $names[] = $functionName;
        }

        return $names;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getSequencesStructure(Connection $conn, string $filter, string $matchMode): array
    {
        $sequences = [];

        try {
            foreach ($conn->createSchemaManager()->introspectSequences() as $sequence) {
                $seqName = $sequence->getObjectName()->toString();

                if (!$this->matchesFilter($seqName, $filter, $matchMode)) {
                    continue;
                }

                $sequences[$seqName] = [
                    'allocation_size' => $sequence->getAllocationSize(),
                    'initial_value' => $sequence->getInitialValue(),
                ];
            }
        } catch (\Exception) {
            // Platform might not support sequences
        }

        return $sequences;
    }

    /** @return list<string> */
    private function getSequencesNames(Connection $conn, string $filter, string $matchMode): array
    {
        $names = [];

        try {
            foreach ($conn->createSchemaManager()->introspectSequences() as $sequence) {
                $sequenceName = $sequence->getObjectName()->toString();

                if (!$this->matchesFilter($sequenceName, $filter, $matchMode)) {
                    continue;
                }

                $names[] = $sequenceName;
            }
        } catch (\Exception) {
            // Platform might not support sequences
        }

        return $names;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getTableTriggers(Connection $conn, string $tableName): array
    {
        $driver = $this->detectDriver($conn);

        try {
            if (str_contains($driver, 'mysql') || str_contains($driver, 'mariadb')) {
                $stmt = $conn->executeQuery(
                    'SELECT TRIGGER_NAME AS name, EVENT_MANIPULATION AS event, ACTION_TIMING AS timing, ACTION_STATEMENT AS statement
                     FROM information_schema.TRIGGERS
                     WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?',
                    [$tableName]
                );

                return $stmt->fetchAllAssociative();
            }
            if (str_contains($driver, 'postgres')) {
                $stmt = $conn->executeQuery(
                    'SELECT trigger_name AS name, event_manipulation AS event, action_timing AS timing, action_statement AS statement
                     FROM information_schema.triggers
                     WHERE trigger_schema = current_schema() AND event_object_table = ?',
                    [$tableName]
                );

                return $stmt->fetchAllAssociative();
            }
            if (str_contains($driver, 'sqlite')) {
                $stmt = $conn->executeQuery(
                    "SELECT name, sql AS statement FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?",
                    [$tableName]
                );

                return $stmt->fetchAllAssociative();
            }
            if (str_contains($driver, 'sqlsrv') || str_contains($driver, 'sqlserver')) {
                $stmt = $conn->executeQuery(
                    'SELECT t.name, te.type_desc AS event
                     FROM sys.triggers t
                     JOIN sys.trigger_events te ON t.object_id = te.object_id
                     JOIN sys.tables tbl ON t.parent_id = tbl.object_id
                     WHERE tbl.name = ?',
                    [$tableName]
                );

                return $stmt->fetchAllAssociative();
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get triggers', ['table' => $tableName, 'error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getTableCheckConstraints(Connection $conn, string $tableName): array
    {
        $driver = $this->detectDriver($conn);

        try {
            if (str_contains($driver, 'mysql') || str_contains($driver, 'mariadb')) {
                $stmt = $conn->executeQuery(
                    'SELECT cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS definition
                     FROM information_schema.CHECK_CONSTRAINTS cc
                     JOIN information_schema.TABLE_CONSTRAINTS tc
                         ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
                         AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
                     WHERE tc.CONSTRAINT_TYPE = \'CHECK\'
                       AND tc.CONSTRAINT_SCHEMA = DATABASE()
                       AND tc.TABLE_NAME = ?',
                    [$tableName]
                );

                return $stmt->fetchAllAssociative();
            }
            if (str_contains($driver, 'postgres')) {
                $stmt = $conn->executeQuery(
                    "SELECT conname AS name, pg_get_constraintdef(oid) AS definition
                     FROM pg_constraint
                     WHERE contype = 'c' AND conrelid = ?::regclass",
                    [$tableName]
                );

                return $stmt->fetchAllAssociative();
            }
            if (str_contains($driver, 'sqlite')) {
                return [];
            }
            if (str_contains($driver, 'sqlsrv') || str_contains($driver, 'sqlserver')) {
                $stmt = $conn->executeQuery(
                    'SELECT cc.name, cc.definition
                     FROM sys.check_constraints cc
                     JOIN sys.tables t ON cc.parent_object_id = t.object_id
                     WHERE t.name = ?',
                    [$tableName]
                );

                return $stmt->fetchAllAssociative();
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get check constraints', ['table' => $tableName, 'error' => $e->getMessage()]);
        }

        return [];
    }

    private function normalizeDetail(string $detail): string
    {
        $normalized = strtolower(trim($detail));

        return match ($normalized) {
            self::DETAIL_FULL => self::DETAIL_FULL,
            self::DETAIL_COLUMNS => self::DETAIL_COLUMNS,
            default => self::DETAIL_SUMMARY,
        };
    }

    private function normalizeMatchMode(string $matchMode): string
    {
        $normalized = strtolower(trim($matchMode));

        return match ($normalized) {
            self::MATCH_MODE_PREFIX,
            self::MATCH_MODE_EXACT,
            self::MATCH_MODE_GLOB,
            self::MATCH_MODE_CONTAINS => $normalized,
            default => self::MATCH_MODE_CONTAINS,
        };
    }

    private function matchesFilter(string $objectName, string $filter, string $matchMode): bool
    {
        if ('' === trim($filter)) {
            return true;
        }

        $normalizedName = strtolower(trim($objectName, '"\' '));
        $normalizedFilter = strtolower(trim($filter));
        $normalizedMode = $this->normalizeMatchMode($matchMode);

        return match ($normalizedMode) {
            self::MATCH_MODE_PREFIX => str_starts_with($normalizedName, $normalizedFilter),
            self::MATCH_MODE_EXACT => $normalizedName === $normalizedFilter,
            self::MATCH_MODE_GLOB => fnmatch($normalizedFilter, $normalizedName),
            default => str_contains($normalizedName, $normalizedFilter),
        };
    }

    private function detectDriver(Connection $conn): string
    {
        $driver = \get_class($conn->getDatabasePlatform());

        return strtolower(basename(str_replace('\\', '/', $driver)));
    }
}
