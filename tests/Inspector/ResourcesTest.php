<?php

declare(strict_types=1);

namespace App\Tests\Inspector;

final class ResourcesTest extends InspectorSnapshotTestCase
{
    private const POSTGRES_DSN = 'postgres://test_user:test_password@127.0.0.1:15432/mcp_test?serverVersion=16&charset=utf8';

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanupDatabase();

        // Initialize all test databases with fixtures
        $this->initializeTestDatabases();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->cleanupDatabase();
    }

    /**
     * @return array<string, array{method: string, options?: array<string, mixed>, testName?: string|null}>
     */
    public static function provideMethods(): array
    {
        $baseTests = [
            'Resource Listing' => ['method' => 'resources/list'],
            'Resource Template Listing' => ['method' => 'resources/templates/list'],
        ];

        $envVars = [
            'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
            'POSTGRES_DSN' => self::POSTGRES_DSN,
        ];

        // Add resource read tests for db://{connection} (list tables)
        foreach (['local', 'products', 'users', 'server'] as $connection) {
            $baseTests[\sprintf('Connection Resource - %s', $connection)] = [
                'method' => 'resources/read',
                'options' => [
                    'uri' => \sprintf('db://%s', $connection),
                    'envVars' => $envVars,
                ],
                'testName' => \sprintf('connection_%s', $connection),
            ];
        }

        // Add resource read tests for db://{connection}/{table} (table schema)
        foreach (['local', 'products', 'users', 'server'] as $connection) {
            $baseTests[\sprintf('Table Resource - %s', $connection)] = [
                'method' => 'resources/read',
                'options' => [
                    'uri' => \sprintf('db://%s/users', $connection),
                    'envVars' => $envVars,
                ],
                'testName' => \sprintf('table_%s', $connection),
            ];
        }

        return $baseTests;
    }

    protected function getSnapshotFilePath(string $method, ?string $testName = null): string
    {
        $methodSlug = str_replace('/', '_', $method);
        $testSlug = $testName ? '_'.$testName : '';

        return \sprintf(
            '%s/tests/Inspector/__snapshots__/Resources/%s%s.json',
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

    private function cleanupDatabase(): void
    {
        $file = \dirname(__DIR__, 2).'/var/test.sqlite';
        if (file_exists($file)) {
            unlink($file);
        }
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
