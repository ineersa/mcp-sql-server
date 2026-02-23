<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DatabaseSchemaService;
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

        $this->service = new DatabaseSchemaService(new ArrayAdapter(), new NullLogger());
    }

    public function testSummaryDetailReturnsOnlyMatchingTableNames(): void
    {
        $result = $this->service->getSchemaStructure(
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
