<?php

declare(strict_types=1);

namespace App\Tests\Inspector;

final class DatabaseMcpCommandTest extends InspectorSnapshotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize all test databases with fixtures
        $this->initializeTestDatabases();
    }

    /**
     * @return array<string, array{method: string, options?: array<string, mixed>, testName?: string|null}>
     */
    public static function provideMethods(): array
    {
        $baseTests = [
            'Tool Listing' => ['method' => 'tools/list'],
        ];

        // Add query execution tests for each database
        foreach (['sqlite', 'mysql', 'postgres', 'sqlserver'] as $connection) {
            $baseTests[\sprintf('Query Execution - %s', $connection)] = [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'QueryTool',
                    'toolArgs' => [
                        'connection' => $connection,
                        'query' => 'SELECT * FROM users ORDER BY id',
                    ],
                    'envVars' => [
                        'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                    ],
                ],
                'testName' => $connection,
            ];
        }

        return $baseTests;
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
     * Initialize all test databases with schema and fixtures.
     */
    private function initializeTestDatabases(): void
    {
        // Load the test database configuration
        $_ENV['DATABASE_CONFIG_FILE'] = \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2));

        // Create DoctrineConfigLoader and load connections
        $logger = new \Psr\Log\NullLogger();
        $loader = new \App\Service\DoctrineConfigLoader($logger);
        $loader->loadAndValidate();

        // Setup fixtures for all connections
        foreach ($loader->getAllConnections() as $name => $connection) {
            try {
                \App\Tests\Fixtures\DatabaseFixtures::setup($connection);
            } catch (\Exception $e) {
                // Skip connections that fail (e.g., if Docker isn't running or driver not available)
                // Tests will fail later with more specific error messages
                continue;
            }
        }
    }
}
