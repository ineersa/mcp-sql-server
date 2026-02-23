<?php

declare(strict_types=1);

namespace App\Tests\Tools;

use App\Service\DatabaseSchemaService;
use App\Service\DoctrineConfigLoader;
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
        $schemaService = new DatabaseSchemaService(new ArrayAdapter(), $logger);
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

    public function testAllowsLargeFullOutputForSingleTable(): void
    {
        $this->createWideTables(tableCount: 1, columnCount: 500);

        $result = ($this->schemaTool)('local', 'wide_table_1', 'full', 'exact');

        $this->assertFalse($result->isError);
        $this->assertCount(1, $result->content);
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
