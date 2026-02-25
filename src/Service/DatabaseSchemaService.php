<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\SchemaDetail;
use App\Enum\SchemaMatchMode;
use App\Exception\ToolUsageError;
use App\Service\Schema\DriverSchemaInspectorInterface;
use App\Service\Schema\SchemaInspectorFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class DatabaseSchemaService
{
    public function __construct(
        private CacheInterface $cache,
        private SchemaInspectorFactory $schemaInspectorFactory,
    ) {
    }

    /** @return array<string, mixed> */
    public function getSchemaStructure(
        string $connectionName,
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
        $shouldIncludeViews = $includeViews || SchemaDetail::FULL->value === $normalizedDetail;
        $shouldIncludeRoutines = $includeRoutines || SchemaDetail::FULL->value === $normalizedDetail;
        $schemaInspector = $this->schemaInspectorFactory->create($conn);

        $cacheKey = \sprintf(
            'database_schema_%s_%s_%s_%s_%d_%d',
            md5($connectionName),
            md5($filter),
            md5($normalizedDetail),
            md5($normalizedMatchMode),
            (int) $shouldIncludeViews,
            (int) $shouldIncludeRoutines
        );

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($conn, $engineName, $filter, $normalizedDetail, $normalizedMatchMode, $shouldIncludeViews, $shouldIncludeRoutines, $schemaInspector) {
            $item->expiresAfter(60);

            return $this->buildSchemaStructure(
                $conn,
                $engineName,
                $schemaInspector,
                $filter,
                $normalizedDetail,
                $normalizedMatchMode,
                $shouldIncludeViews,
                $shouldIncludeRoutines,
            );
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
        $schemaInspector = $this->schemaInspectorFactory->create($conn);

        return [
            'stored_procedures' => $schemaInspector->getStoredProcedures($conn),
            'functions' => $schemaInspector->getFunctions($conn),
        ];
    }

    /** @return array<string, mixed> */
    private function buildSchemaStructure(
        Connection $conn,
        string $engineName,
        DriverSchemaInspectorInterface $schemaInspector,
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
                SchemaDetail::SUMMARY->value => $this->getAllTableNames($conn, $filter, $normalizedMatchMode),
                SchemaDetail::COLUMNS->value => $this->getAllTableColumnsStructure($conn, $filter, $normalizedMatchMode),
                default => $this->getAllTablesStructure($conn, $schemaInspector, $filter, $normalizedMatchMode),
            },
        ];

        if ($includeViews) {
            $result['views'] = SchemaDetail::FULL->value === $normalizedDetail
                ? $this->getViewsStructure($conn, $filter, $normalizedMatchMode)
                : $this->getViewNames($conn, $filter, $normalizedMatchMode);
        }

        if ($includeRoutines) {
            $routines = [
                'stored_procedures' => SchemaDetail::FULL->value === $normalizedDetail
                    ? $this->getStoredProceduresStructure($conn, $schemaInspector, $filter, $normalizedMatchMode)
                    : $this->getStoredProceduresNames($conn, $schemaInspector, $filter, $normalizedMatchMode),
                'functions' => SchemaDetail::FULL->value === $normalizedDetail
                    ? $this->getFunctionsStructure($conn, $schemaInspector, $filter, $normalizedMatchMode)
                    : $this->getFunctionsNames($conn, $schemaInspector, $filter, $normalizedMatchMode),
                'sequences' => SchemaDetail::FULL->value === $normalizedDetail
                    ? $this->getSequencesStructure($conn, $filter, $normalizedMatchMode)
                    : $this->getSequencesNames($conn, $filter, $normalizedMatchMode),
            ];

            if (SchemaDetail::FULL->value !== $normalizedDetail) {
                $routines['triggers'] = $this->getTriggersNames($conn, $schemaInspector, $filter, $normalizedMatchMode);
            }

            $result['routines'] = $routines;
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getAllTablesStructure(Connection $conn, DriverSchemaInspectorInterface $schemaInspector, string $filter, string $matchMode): array
    {
        $schemaManager = $conn->createSchemaManager();
        $structures = [];

        foreach ($schemaManager->introspectTables() as $table) {
            $tableName = $table->getObjectName()->toString();
            $tableNameMatchesFilter = $this->matchesFilter($tableName, $filter, $matchMode);

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

                $columns[$this->unquoteIdentifier($column->getObjectName()->toString())] = $detail;
            }

            $primaryKey = $table->getPrimaryKeyConstraint();

            $indexes = [];
            foreach ($table->getIndexes() as $index) {
                $indexName = $index->getObjectName()->toString();
                $indexType = $index->getType();
                $indexedColumns = array_map(
                    static fn ($col) => $col->getColumnName()->toString(),
                    $index->getIndexedColumns()
                );

                $indexes[$indexName] = [
                    'columns' => $indexedColumns,
                    'is_unique' => IndexType::UNIQUE === $indexType,
                    'is_primary' => $this->isPrimaryIndex($indexedColumns, $primaryKey),
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
            $tableTriggers = $schemaInspector->getTableTriggers($conn, $rawTableName);
            $matchingTriggers = $this->filterTableTriggers($tableTriggers, $filter, $matchMode);

            if (!$tableNameMatchesFilter && '' !== trim($filter) && [] === $matchingTriggers) {
                continue;
            }

            $triggersToReturn = $tableNameMatchesFilter || '' === trim($filter)
                ? $tableTriggers
                : $matchingTriggers;

            $structures[$tableName] = [
                'columns' => $columns,
                'indexes' => $indexes,
                'foreign_keys' => $foreignKeys,
                'triggers' => $triggersToReturn,
                'check_constraints' => $schemaInspector->getTableCheckConstraints($conn, $rawTableName),
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
                $columns[$this->unquoteIdentifier($column->getObjectName()->toString())] = str_replace('Type', '', end($typeParts));
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
     * @return array<string, array<string, mixed>>
     */
    private function getStoredProceduresStructure(Connection $conn, DriverSchemaInspectorInterface $schemaInspector, string $filter, string $matchMode): array
    {
        $structures = [];

        foreach ($schemaInspector->getStoredProcedures($conn) as $proc) {
            if (!$this->matchesFilter($proc, $filter, $matchMode)) {
                continue;
            }
            $structures[$proc] = ['type' => 'procedure'];
        }

        return $structures;
    }

    /** @return list<string> */
    private function getStoredProceduresNames(Connection $conn, DriverSchemaInspectorInterface $schemaInspector, string $filter, string $matchMode): array
    {
        $names = [];

        foreach ($schemaInspector->getStoredProcedures($conn) as $procedureName) {
            if (!$this->matchesFilter($procedureName, $filter, $matchMode)) {
                continue;
            }

            $names[] = $procedureName;
        }

        return $names;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getFunctionsStructure(Connection $conn, DriverSchemaInspectorInterface $schemaInspector, string $filter, string $matchMode): array
    {
        $structures = [];

        foreach ($schemaInspector->getFunctions($conn) as $func) {
            if (!$this->matchesFilter($func, $filter, $matchMode)) {
                continue;
            }
            $structures[$func] = ['type' => 'function'];
        }

        return $structures;
    }

    /** @return list<string> */
    private function getFunctionsNames(Connection $conn, DriverSchemaInspectorInterface $schemaInspector, string $filter, string $matchMode): array
    {
        $names = [];

        foreach ($schemaInspector->getFunctions($conn) as $functionName) {
            if (!$this->matchesFilter($functionName, $filter, $matchMode)) {
                continue;
            }

            $names[] = $functionName;
        }

        return $names;
    }

    /** @return list<string> */
    private function getTriggersNames(Connection $conn, DriverSchemaInspectorInterface $schemaInspector, string $filter, string $matchMode): array
    {
        $names = [];

        foreach ($schemaInspector->getTriggers($conn) as $triggerName) {
            if (!$this->matchesFilter($triggerName, $filter, $matchMode)) {
                continue;
            }

            $names[] = $triggerName;
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

    private function normalizeDetail(string $detail): string
    {
        $detailEnum = SchemaDetail::tryFromInput($detail);
        if (null === $detailEnum) {
            throw new ToolUsageError(message: \sprintf('Invalid detail value "%s".', $detail), hint: \sprintf('Use one of: %s.', implode(', ', SchemaDetail::values())), retryable: false);
        }

        return $detailEnum->value;
    }

    private function normalizeMatchMode(string $matchMode): string
    {
        $modeEnum = SchemaMatchMode::tryFromInput($matchMode);
        if (null === $modeEnum) {
            throw new ToolUsageError(message: \sprintf('Invalid matchMode value "%s".', $matchMode), hint: \sprintf('Use one of: %s.', implode(', ', SchemaMatchMode::values())), retryable: false);
        }

        return $modeEnum->value;
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
            SchemaMatchMode::PREFIX->value => str_starts_with($normalizedName, $normalizedFilter),
            SchemaMatchMode::EXACT->value => $normalizedName === $normalizedFilter,
            SchemaMatchMode::GLOB->value => fnmatch($normalizedFilter, $normalizedName),
            default => str_contains($normalizedName, $normalizedFilter),
        };
    }

    /**
     * @param list<string> $indexColumns
     */
    private function isPrimaryIndex(array $indexColumns, ?PrimaryKeyConstraint $primaryKey): bool
    {
        if (null === $primaryKey) {
            return false;
        }

        $normalizedIndexColumns = array_map($this->normalizeIdentifier(...), $indexColumns);
        sort($normalizedIndexColumns);

        $primaryColumns = array_map(
            fn ($columnName): string => $this->normalizeIdentifier($columnName->toString()),
            $primaryKey->getColumnNames()
        );
        sort($primaryColumns);

        return $normalizedIndexColumns === $primaryColumns;
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return strtolower(trim($identifier, '"\'`[] '));
    }

    private function unquoteIdentifier(string $identifier): string
    {
        return trim($identifier, '"\'`[] ');
    }

    /**
     * @param array<int, array<string, mixed>> $triggers
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterTableTriggers(array $triggers, string $filter, string $matchMode): array
    {
        if ('' === trim($filter)) {
            return $triggers;
        }

        return array_values(array_filter(
            $triggers,
            fn (array $trigger): bool => $this->matchesFilter((string) ($trigger['name'] ?? ''), $filter, $matchMode),
        ));
    }
}
