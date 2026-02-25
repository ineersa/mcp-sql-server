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
        $shouldIncludeViews = $includeViews;
        $shouldIncludeRoutines = $includeRoutines;
        $shouldIncludeDefinitions = SchemaDetail::FULL->value === $normalizedDetail;
        $schemaInspector = $this->schemaInspectorFactory->create($conn);

        $cacheKey = \sprintf(
            'database_schema_%s_%s_%s_%s_%d_%d_%d',
            md5($connectionName),
            md5($filter),
            md5($normalizedDetail),
            md5($normalizedMatchMode),
            (int) $shouldIncludeViews,
            (int) $shouldIncludeRoutines,
            (int) $shouldIncludeDefinitions,
        );

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($conn, $engineName, $filter, $normalizedDetail, $normalizedMatchMode, $shouldIncludeViews, $shouldIncludeRoutines, $shouldIncludeDefinitions, $schemaInspector) {
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
                $shouldIncludeDefinitions,
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
        bool $includeDefinitions,
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
                default => $this->getAllTablesStructure($conn, $schemaInspector, $filter, $normalizedMatchMode, $includeDefinitions),
            },
        ];

        if ($includeViews) {
            $result['views'] = SchemaDetail::FULL->value === $normalizedDetail
                ? $this->getViewsStructure($conn, $filter, $normalizedMatchMode, $includeDefinitions)
                : $this->getViewNames($conn, $filter, $normalizedMatchMode);
        }

        if ($includeRoutines) {
            $routines = [
                'stored_procedures' => SchemaDetail::FULL->value === $normalizedDetail
                    ? $this->getStoredProceduresStructure($conn, $schemaInspector, $filter, $normalizedMatchMode, $includeDefinitions)
                    : $this->getStoredProceduresNames($conn, $schemaInspector, $filter, $normalizedMatchMode),
                'functions' => SchemaDetail::FULL->value === $normalizedDetail
                    ? $this->getFunctionsStructure($conn, $schemaInspector, $filter, $normalizedMatchMode, $includeDefinitions)
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

        if (
            SchemaMatchMode::EXACT->value === $normalizedMatchMode
            && '' !== trim($filter)
            && !$this->hasAnySchemaMatches($result)
        ) {
            $result['diagnostics'] = $this->buildNoMatchDiagnostics(
                $conn,
                $schemaInspector,
                $filter,
                $includeViews,
                $includeRoutines,
            );
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getAllTablesStructure(Connection $conn, DriverSchemaInspectorInterface $schemaInspector, string $filter, string $matchMode, bool $includeDefinitions): array
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

            if ($includeDefinitions) {
                $triggersToReturn = $this->enrichTriggersWithDefinitions($conn, $schemaInspector, $triggersToReturn);
            }

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
    private function getViewsStructure(Connection $conn, string $filter, string $matchMode, bool $includeDefinitions): array
    {
        $views = [];

        foreach ($conn->createSchemaManager()->introspectViews() as $view) {
            $viewName = $view->getObjectName()->toString();

            if (!$this->matchesFilter($viewName, $filter, $matchMode)) {
                continue;
            }

            $views[$viewName] = ['type' => 'view'];

            if ($includeDefinitions) {
                $views[$viewName]['definition'] = $view->getSql();
            }
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
    private function getStoredProceduresStructure(Connection $conn, DriverSchemaInspectorInterface $schemaInspector, string $filter, string $matchMode, bool $includeDefinitions): array
    {
        $structures = [];

        foreach ($schemaInspector->getStoredProcedures($conn) as $proc) {
            if (!$this->matchesFilter($proc, $filter, $matchMode)) {
                continue;
            }

            $details = ['type' => 'procedure'];

            if ($includeDefinitions) {
                $definition = $schemaInspector->getStoredProcedureDefinition($conn, $proc);
                if (null !== $definition && '' !== trim($definition)) {
                    $details['definition'] = $definition;
                }
            }

            $structures[$proc] = $details;
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
    private function getFunctionsStructure(Connection $conn, DriverSchemaInspectorInterface $schemaInspector, string $filter, string $matchMode, bool $includeDefinitions): array
    {
        $structures = [];

        foreach ($schemaInspector->getFunctions($conn) as $func) {
            if (!$this->matchesFilter($func, $filter, $matchMode)) {
                continue;
            }

            $details = ['type' => 'function'];

            if ($includeDefinitions) {
                $definition = $schemaInspector->getFunctionDefinition($conn, $func);
                if (null !== $definition && '' !== trim($definition)) {
                    $details['definition'] = $definition;
                }
            }

            $structures[$func] = $details;
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

        $normalizedName = $this->normalizeFilterTarget($objectName);
        $normalizedFilter = $this->normalizeFilterTarget($filter);
        $normalizedMode = $this->normalizeMatchMode($matchMode);

        return match ($normalizedMode) {
            SchemaMatchMode::PREFIX->value => $this->matchesPrefix($normalizedName, $normalizedFilter),
            SchemaMatchMode::EXACT->value => $this->matchesExact($normalizedName, $normalizedFilter),
            SchemaMatchMode::GLOB->value => $this->matchesGlob($normalizedName, $normalizedFilter),
            default => $this->matchesContains($normalizedName, $normalizedFilter),
        };
    }

    /**
     * @param array{canonical: string, leaf: string} $name
     * @param array{canonical: string, leaf: string} $filter
     */
    private function matchesPrefix(array $name, array $filter): bool
    {
        return str_starts_with($name['canonical'], $filter['canonical'])
            || str_starts_with($name['leaf'], $filter['leaf']);
    }

    /**
     * @param array{canonical: string, leaf: string} $name
     * @param array{canonical: string, leaf: string} $filter
     */
    private function matchesExact(array $name, array $filter): bool
    {
        return $name['canonical'] === $filter['canonical']
            || $name['leaf'] === $filter['leaf'];
    }

    /**
     * @param array{canonical: string, leaf: string} $name
     * @param array{canonical: string, leaf: string} $filter
     */
    private function matchesGlob(array $name, array $filter): bool
    {
        return fnmatch($filter['canonical'], $name['canonical'])
            || fnmatch($filter['leaf'], $name['leaf']);
    }

    /**
     * @param array{canonical: string, leaf: string} $name
     * @param array{canonical: string, leaf: string} $filter
     */
    private function matchesContains(array $name, array $filter): bool
    {
        return str_contains($name['canonical'], $filter['canonical'])
            || str_contains($name['leaf'], $filter['leaf']);
    }

    /**
     * @return array{canonical: string, leaf: string}
     */
    private function normalizeFilterTarget(string $value): array
    {
        $trimmedValue = trim($value);
        $parts = preg_split('/\s*\.\s*/', $trimmedValue);
        if (false === $parts || [] === $parts) {
            $normalized = $this->normalizeIdentifier($trimmedValue);

            return [
                'canonical' => $normalized,
                'leaf' => $normalized,
            ];
        }

        $normalizedParts = [];
        foreach ($parts as $part) {
            $normalizedPart = $this->normalizeIdentifier($part);
            if ('' === $normalizedPart) {
                continue;
            }

            $normalizedParts[] = $normalizedPart;
        }

        if ([] === $normalizedParts) {
            $normalized = $this->normalizeIdentifier($trimmedValue);

            return [
                'canonical' => $normalized,
                'leaf' => $normalized,
            ];
        }

        $canonical = implode('.', $normalizedParts);

        return [
            'canonical' => $canonical,
            'leaf' => end($normalizedParts) ?: $canonical,
        ];
    }

    /** @param array<string, mixed> $result */
    private function hasAnySchemaMatches(array $result): bool
    {
        if (isset($result['tables']) && \is_array($result['tables']) && [] !== $result['tables']) {
            return true;
        }

        if (isset($result['views']) && \is_array($result['views']) && [] !== $result['views']) {
            return true;
        }

        if (!isset($result['routines']) || !\is_array($result['routines'])) {
            return false;
        }

        foreach ($result['routines'] as $routineGroup) {
            if (\is_array($routineGroup) && [] !== $routineGroup) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildNoMatchDiagnostics(
        Connection $conn,
        DriverSchemaInspectorInterface $schemaInspector,
        string $filter,
        bool $includeViews,
        bool $includeRoutines,
    ): array {
        $normalizedFilter = $this->normalizeFilterTarget($filter);
        $candidates = $this->collectSchemaCandidates($conn, $schemaInspector, $includeViews, $includeRoutines);

        return [
            'status' => 'no_exact_match',
            'normalized_filter' => $normalizedFilter,
            'normalized_names_tried' => array_values(array_unique([$normalizedFilter['canonical'], $normalizedFilter['leaf']])),
            'top_near_matches' => $this->findTopNearMatches($normalizedFilter, $candidates),
        ];
    }

    /**
     * @return list<array{name: string, type: string, normalized: array{canonical: string, leaf: string}}>
     */
    private function collectSchemaCandidates(Connection $conn, DriverSchemaInspectorInterface $schemaInspector, bool $includeViews, bool $includeRoutines): array
    {
        $candidates = [];

        foreach ($conn->createSchemaManager()->introspectTables() as $table) {
            $tableName = $table->getObjectName()->toString();
            $candidates[] = [
                'name' => $tableName,
                'type' => 'table',
                'normalized' => $this->normalizeFilterTarget($tableName),
            ];
        }

        if ($includeViews) {
            foreach ($conn->createSchemaManager()->introspectViews() as $view) {
                $viewName = $view->getObjectName()->toString();
                $candidates[] = [
                    'name' => $viewName,
                    'type' => 'view',
                    'normalized' => $this->normalizeFilterTarget($viewName),
                ];
            }
        }

        if ($includeRoutines) {
            foreach ($schemaInspector->getStoredProcedures($conn) as $procedureName) {
                $candidates[] = [
                    'name' => $procedureName,
                    'type' => 'procedure',
                    'normalized' => $this->normalizeFilterTarget($procedureName),
                ];
            }

            foreach ($schemaInspector->getFunctions($conn) as $functionName) {
                $candidates[] = [
                    'name' => $functionName,
                    'type' => 'function',
                    'normalized' => $this->normalizeFilterTarget($functionName),
                ];
            }

            foreach ($schemaInspector->getTriggers($conn) as $triggerName) {
                $candidates[] = [
                    'name' => $triggerName,
                    'type' => 'trigger',
                    'normalized' => $this->normalizeFilterTarget($triggerName),
                ];
            }

            try {
                foreach ($conn->createSchemaManager()->introspectSequences() as $sequence) {
                    $sequenceName = $sequence->getObjectName()->toString();
                    $candidates[] = [
                        'name' => $sequenceName,
                        'type' => 'sequence',
                        'normalized' => $this->normalizeFilterTarget($sequenceName),
                    ];
                }
            } catch (\Exception) {
                // Platform might not support sequences.
            }
        }

        return $candidates;
    }

    /**
     * @param array{canonical: string, leaf: string}                                                      $normalizedFilter
     * @param list<array{name: string, type: string, normalized: array{canonical: string, leaf: string}}> $candidates
     *
     * @return list<array{name: string, type: string, normalized_name: string}>
     */
    private function findTopNearMatches(array $normalizedFilter, array $candidates): array
    {
        $scored = [];

        foreach ($candidates as $candidate) {
            $score = min(
                levenshtein($normalizedFilter['canonical'], $candidate['normalized']['canonical']),
                levenshtein($normalizedFilter['leaf'], $candidate['normalized']['leaf']),
            );

            $scored[] = [
                'name' => $candidate['name'],
                'type' => $candidate['type'],
                'normalized_name' => $candidate['normalized']['canonical'],
                'score' => $score,
            ];
        }

        usort(
            $scored,
            static fn (array $left, array $right): int => $left['score'] <=> $right['score']
                ?: strcmp((string) $left['type'], (string) $right['type'])
                ?: strcmp((string) $left['name'], (string) $right['name']),
        );

        $topMatches = \array_slice($scored, 0, 5);

        return array_map(
            static fn (array $match): array => [
                'name' => (string) $match['name'],
                'type' => (string) $match['type'],
                'normalized_name' => (string) $match['normalized_name'],
            ],
            $topMatches,
        );
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

    /**
     * @param array<int, array<string, mixed>> $triggers
     *
     * @return array<int, array<string, mixed>>
     */
    private function enrichTriggersWithDefinitions(Connection $conn, DriverSchemaInspectorInterface $schemaInspector, array $triggers): array
    {
        return array_map(static function (array $trigger) use ($conn, $schemaInspector): array {
            $triggerName = $trigger['name'] ?? null;
            if (!\is_string($triggerName) || '' === trim($triggerName)) {
                return $trigger;
            }

            $definition = $schemaInspector->getTriggerDefinition($conn, $triggerName);
            if (null === $definition || '' === trim($definition)) {
                return $trigger;
            }

            $trigger['definition'] = $definition;

            return $trigger;
        }, $triggers);
    }
}
