<?php

declare(strict_types=1);

namespace App\Tests\Tools;

use App\Service\DatabaseSchemaService;
use App\Service\DoctrineConfigLoader;
use App\Service\Schema\MysqlSchemaInspector;
use App\Service\Schema\PostgreSqlSchemaInspector;
use App\Service\Schema\SchemaInspectorFactory;
use App\Service\Schema\SqliteSchemaInspector;
use App\Service\Schema\SqlServerSchemaInspector;
use App\Tools\SchemaTool;
use Doctrine\DBAL\Connection;
use Mcp\Schema\Content\TextContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(SchemaTool::class)]
final class SchemaToolTest extends TestCase
{
    private Filesystem $filesystem;
    private string $tempDir;
    private string $databasePath;
    private string $configPath;
    private ?string $originalDatabaseConfigFile = null;
    private Connection $connection;
    private SchemaTool $schemaTool;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir().'/schema_tool_test_'.uniqid();
        $this->filesystem->mkdir($this->tempDir);

        $this->databasePath = $this->tempDir.'/test.sqlite';
        $this->configPath = $this->tempDir.'/databases.test.yaml';
        $this->originalDatabaseConfigFile = $_ENV['DATABASE_CONFIG_FILE'] ?? null;

        file_put_contents($this->configPath, <<<YAML
doctrine:
    dbal:
        connections:
            local:
                driver: "pdo_sqlite"
                path: "{$this->databasePath}"
YAML);

        $_ENV['DATABASE_CONFIG_FILE'] = $this->configPath;

        $logger = new NullLogger();
        $loader = new DoctrineConfigLoader($logger);
        $loader->loadAndValidate();

        $this->connection = $loader->getConnection('local');
        $schemaInspectorFactory = new SchemaInspectorFactory(
            new MysqlSchemaInspector($logger),
            new PostgreSqlSchemaInspector($logger),
            new SqliteSchemaInspector($logger),
            new SqlServerSchemaInspector($logger),
        );
        $schemaService = new DatabaseSchemaService(new ArrayAdapter(), $schemaInspectorFactory);
        $this->schemaTool = new SchemaTool($schemaService, $loader, $logger);
    }

    protected function tearDown(): void
    {
        if (null !== $this->originalDatabaseConfigFile) {
            $_ENV['DATABASE_CONFIG_FILE'] = $this->originalDatabaseConfigFile;
        } else {
            unset($_ENV['DATABASE_CONFIG_FILE']);
        }

        if ($this->filesystem->exists($this->tempDir)) {
            $this->filesystem->remove($this->tempDir);
        }
    }

    public function testRejectsLargeFullOutputWhenMultipleTablesMatch(): void
    {
        $this->createWideTables(tableCount: 2, columnCount: 250);

        $result = ($this->schemaTool)('local', '', 'full');

        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);

        $content = $result->content[0];
        $this->assertInstanceOf(TextContent::class, $content);
        /** @var TextContent $content */
        $payload = (string) $content->text;
        $this->assertStringContainsString('too large', strtolower($payload));
        $this->assertStringContainsString('switch to detail', strtolower($payload));
    }

    public function testRejectsLargeFullOutputForSingleTable(): void
    {
        $this->createWideTables(tableCount: 1, columnCount: 500);

        $result = ($this->schemaTool)('local', 'wide_table_1', 'full', 'exact');

        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);

        $content = $result->content[0];
        $this->assertInstanceOf(TextContent::class, $content);
        /** @var TextContent $content */
        $payload = (string) $content->text;
        $this->assertStringContainsString('too large', strtolower($payload));
    }

    public function testRejectsLargeColumnsOutputWhenMultipleTablesMatch(): void
    {
        $this->createWideTables(tableCount: 2, columnCount: 250);

        $result = ($this->schemaTool)('local', '', 'columns');

        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);

        $content = $result->content[0];
        $this->assertInstanceOf(TextContent::class, $content);
        /** @var TextContent $content */
        $payload = (string) $content->text;
        $this->assertStringContainsString('too large', strtolower($payload));
    }

    public function testFilterDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');

        $result = ($this->schemaTool)('local');

        $this->assertFalse($result->isError);
        $this->assertCount(1, $result->content);

        $content = $result->content[0];
        $this->assertInstanceOf(TextContent::class, $content);
        /** @var TextContent $content */
        $payload = (string) $content->text;
        $this->assertStringContainsString('users', strtolower($payload));
    }

    public function testRejectsInvalidDetailValueWithExplicitHint(): void
    {
        $result = ($this->schemaTool)('local', '', 'unknown-detail');

        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);

        $content = $result->content[0];
        $this->assertInstanceOf(TextContent::class, $content);
        /** @var TextContent $content */
        $payload = strtolower((string) $content->text);
        $this->assertStringContainsString('invalid detail value', $payload);
        $this->assertStringContainsString('use one of', $payload);
        $this->assertStringContainsString('summary', $payload);
        $this->assertStringContainsString('columns', $payload);
        $this->assertStringContainsString('full', $payload);
    }

    public function testRejectsInvalidMatchModeValueWithExplicitHint(): void
    {
        $result = ($this->schemaTool)('local', '', 'summary', 'unknown-mode');

        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);

        $content = $result->content[0];
        $this->assertInstanceOf(TextContent::class, $content);
        /** @var TextContent $content */
        $payload = strtolower((string) $content->text);
        $this->assertStringContainsString('invalid matchmode value', $payload);
        $this->assertStringContainsString('use one of', $payload);
        $this->assertStringContainsString('contains', $payload);
        $this->assertStringContainsString('prefix', $payload);
        $this->assertStringContainsString('exact', $payload);
        $this->assertStringContainsString('glob', $payload);
    }

    public function testFullDetailIncludesViewDefinitions(): void
    {
        $this->connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
        $this->connection->executeStatement('CREATE VIEW active_users AS SELECT id, email FROM users');

        $result = ($this->schemaTool)('local', 'active_users', 'full', 'exact', true, false);

        $this->assertFalse($result->isError);
        $this->assertCount(1, $result->content);

        $content = $result->content[0];
        $this->assertInstanceOf(TextContent::class, $content);

        /** @var TextContent $content */
        $payload = strtolower((string) $content->text);

        $this->assertStringContainsString('definition', $payload);
        $this->assertStringContainsString('active_users', $payload);
    }

    private function createWideTables(int $tableCount, int $columnCount): void
    {
        for ($table = 1; $table <= $tableCount; ++$table) {
            $columns = ['id INTEGER PRIMARY KEY'];

            for ($column = 1; $column <= $columnCount; ++$column) {
                $columns[] = \sprintf('column_%d TEXT', $column);
            }

            $this->connection->executeStatement(\sprintf(
                'CREATE TABLE wide_table_%d (%s)',
                $table,
                implode(', ', $columns),
            ));
        }
    }
}
