<?php

declare(strict_types=1);

namespace App\Tests\Inspector;

final class DatabaseMcpCommandTest extends InspectorSnapshotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize SQLite database with fixtures for testing
        $this->initializeTestDatabase();
    }

    /**
     * @return array<string, array{method: string, options?: array<string, mixed>, testName?: string|null}>
     */
    public static function provideMethods(): array
    {
        return [
            'Tool Listing' => ['method' => 'tools/list'],
            'Query Execution' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'QueryTool',
                    'toolArgs' => [
                        'connection' => 'test',
                        'query' => 'SELECT * FROM users ORDER BY id',
                    ],
                    'envVars' => [
                        'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                    ],
                ],
            ],
        ];
    }

    protected function getSnapshotFilePath(string $method, ?string $testName = null): string
    {
        $methodSlug = str_replace('/', '_', $method);
        $testSlug = $testName ? '_'.$testName : '';

        return \sprintf(
            '%s/tests/Inspector/__snapshots__/DatabaseMcpCommand/%s%s.json',
            \dirname(__DIR__, 2),
            $methodSlug,
            $testSlug
        );
    }

    /** @return array<string> */
    protected function getServerConnectionArgs(): array
    {
        return [
            'php',
            \sprintf('%s/bin/console', \dirname(__DIR__, 2)),
            'database-mcp',
        ];
    }

    protected function getTransport(): string
    {
        return 'stdio';
    }

    /**
     * Initialize the test database with schema and fixtures.
     */
    private function initializeTestDatabase(): void
    {
        // Load the test database configuration
        $_ENV['DATABASE_CONFIG_FILE'] = \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2));

        // Create DoctrineConfigLoader and load connections
        $logger = new \Psr\Log\NullLogger();
        $loader = new \App\Service\DoctrineConfigLoader($logger);
        $loader->loadAndValidate();

        // Get the test connection and setup fixtures
        $connection = $loader->getConnection('test');
        \App\Tests\Fixtures\DatabaseFixtures::setup($connection);
    }
}
