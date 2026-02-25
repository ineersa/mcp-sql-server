<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ToolUsageError;
use App\Service\DatabaseSchemaService;
use App\Service\Schema\MysqlSchemaInspector;
use App\Service\Schema\PostgreSqlSchemaInspector;
use App\Service\Schema\SchemaInspectorFactory;
use App\Service\Schema\SqliteSchemaInspector;
use App\Service\Schema\SqlServerSchemaInspector;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(DatabaseSchemaService::class)]
final class DatabaseSchemaServiceTest extends TestCase
{
    private Connection $connection;
    private DatabaseSchemaService $service;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE user_profiles (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, message TEXT NOT NULL)');
        $this->connection->executeStatement('CREATE VIEW active_users AS SELECT id, email FROM users');
        $this->connection->executeStatement('CREATE TRIGGER trg_users_insert AFTER INSERT ON users BEGIN SELECT 1; END');

        $logger = new NullLogger();
        $schemaInspectorFactory = new SchemaInspectorFactory(
            new MysqlSchemaInspector($logger),
            new PostgreSqlSchemaInspector($logger),
            new SqliteSchemaInspector($logger),
            new SqlServerSchemaInspector($logger),
        );

        $this->service = new DatabaseSchemaService(new ArrayAdapter(), $schemaInspectorFactory);
    }

    public function testSummaryDetailReturnsOnlyMatchingTableNames(): void
    {
        $result = $this->service->getSchemaStructure(
            'local',
            $this->connection,
            'pdo_sqlite',
            'user',
            'summary',
            'contains',
            false,
            false,
        );

        $this->assertSame('summary', $result['detail']);
        $this->assertEqualsCanonicalizing(['users', 'user_profiles'], $this->normalizeObjectNames($result['tables']));
    }

    public function testExactMatchModeReturnsSingleExactName(): void
    {
        $result = $this->service->getSchemaStructure(
            'local',
            $this->connection,
            'pdo_sqlite',
            'users',
            'summary',
            'exact',
            false,
            false,
        );

        $this->assertEqualsCanonicalizing(['users'], $this->normalizeObjectNames($result['tables']));
    }

    public function testGlobMatchModeSupportsWildcards(): void
    {
        $result = $this->service->getSchemaStructure(
            'local',
            $this->connection,
            'pdo_sqlite',
            'user*',
            'summary',
            'glob',
            false,
            false,
        );

        $this->assertEqualsCanonicalizing(['users', 'user_profiles'], $this->normalizeObjectNames($result['tables']));
    }

    public function testColumnsDetailReturnsColumnTypesWithoutFullMetadata(): void
    {
        $result = $this->service->getSchemaStructure(
            'local',
            $this->connection,
            'pdo_sqlite',
            'users',
            'columns',
            'exact',
            false,
            false,
        );

        $this->assertSame('columns', $result['detail']);
        $this->assertIsArray($result['tables']);
        $this->assertCount(1, $result['tables']);

        $table = reset($result['tables']);
        $this->assertIsArray($table);
        $this->assertArrayHasKey('columns', $table);
        $this->assertIsArray($table['columns']);
        $this->assertArrayHasKey('id', $table['columns']);
        $this->assertIsString($table['columns']['id']);
        $this->assertArrayNotHasKey('indexes', $table);
    }

    public function testFullDetailReturnsStructuredTableData(): void
    {
        $result = $this->service->getSchemaStructure(
            'local',
            $this->connection,
            'pdo_sqlite',
            'users',
            'full',
            'exact',
            false,
            false,
        );

        $this->assertSame('full', $result['detail']);
        $this->assertIsArray($result['tables']);
        $this->assertCount(1, $result['tables']);

        $table = reset($result['tables']);
        $this->assertIsArray($table);
        $this->assertArrayHasKey('columns', $table);
        $this->assertArrayHasKey('indexes', $table);
        $this->assertArrayHasKey('foreign_keys', $table);
        $this->assertArrayHasKey('views', $result);
        $this->assertArrayHasKey('routines', $result);
    }

    public function testFullDetailAutomaticallyIncludesViewsAndRoutines(): void
    {
        $result = $this->service->getSchemaStructure(
            'local',
            $this->connection,
            'pdo_sqlite',
            '',
            'full',
            'contains',
            false,
            false,
        );

        $this->assertArrayHasKey('views', $result);
        $this->assertNotEmpty($result['views']);
        $this->assertArrayHasKey('routines', $result);
        $this->assertArrayHasKey('stored_procedures', $result['routines']);
        $this->assertArrayHasKey('functions', $result['routines']);
        $this->assertArrayHasKey('sequences', $result['routines']);
    }

    public function testIncludeRoutinesAddsTriggersForSummaryDetail(): void
    {
        $result = $this->service->getSchemaStructure(
            'local',
            $this->connection,
            'pdo_sqlite',
            'users',
            'summary',
            'contains',
            false,
            true,
        );

        $this->assertArrayHasKey('routines', $result);
        $this->assertIsArray($result['routines']);
        $this->assertArrayHasKey('triggers', $result['routines']);
        $this->assertContains('trg_users_insert', $result['routines']['triggers']);
    }

    public function testFullDetailDoesNotDuplicateTriggersUnderRoutines(): void
    {
        $result = $this->service->getSchemaStructure(
            'local',
            $this->connection,
            'pdo_sqlite',
            'users',
            'full',
            'contains',
            false,
            true,
        );

        $this->assertArrayHasKey('routines', $result);
        $this->assertIsArray($result['routines']);
        $this->assertArrayNotHasKey('triggers', $result['routines']);

        $table = reset($result['tables']);
        $this->assertIsArray($table);
        $this->assertArrayHasKey('triggers', $table);
        $this->assertNotEmpty($table['triggers']);
    }

    public function testFullDetailCanFilterByTriggerName(): void
    {
        $result = $this->service->getSchemaStructure(
            'local',
            $this->connection,
            'pdo_sqlite',
            'trg_users_insert',
            'full',
            'exact',
            false,
            true,
        );

        $this->assertArrayHasKey('tables', $result);
        $this->assertIsArray($result['tables']);
        $this->assertCount(1, $result['tables']);

        $tableName = array_key_first($result['tables']);
        $this->assertIsString($tableName);
        $this->assertSame('users', trim($tableName, '"\' '));

        $table = reset($result['tables']);
        $this->assertIsArray($table);
        $this->assertArrayHasKey('triggers', $table);
        $this->assertCount(1, $table['triggers']);
        $this->assertSame('trg_users_insert', $table['triggers'][0]['name']);
        $this->assertArrayHasKey('routines', $result);
        $this->assertArrayNotHasKey('triggers', $result['routines']);
    }

    public function testInvalidDetailThrowsToolUsageError(): void
    {
        $this->expectException(ToolUsageError::class);
        $this->expectExceptionMessage('Invalid detail value');

        $this->service->getSchemaStructure(
            'local',
            $this->connection,
            'pdo_sqlite',
            'users',
            'invalid-detail',
            'contains',
            false,
            false,
        );
    }

    public function testInvalidMatchModeThrowsToolUsageError(): void
    {
        $this->expectException(ToolUsageError::class);
        $this->expectExceptionMessage('Invalid matchMode value');

        $this->service->getSchemaStructure(
            'local',
            $this->connection,
            'pdo_sqlite',
            'users',
            'summary',
            'invalid-mode',
            false,
            false,
        );
    }

    /**
     * @return list<string>
     */
    private function normalizeObjectNames(mixed $objects): array
    {
        $this->assertIsArray($objects);

        $normalized = [];

        foreach ($objects as $objectName) {
            $normalized[] = trim((string) $objectName, '"\' ');
        }

        return $normalized;
    }
}
