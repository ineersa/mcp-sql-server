<?php

declare(strict_types=1);

namespace App\Tests\Inspector;

final class QueryToolReadOnlyTest extends InspectorSnapshotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanupDatabase();

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
        $tests = [];

        $tests['SELECT Query - local'] = [
            'method' => 'tools/call',
            'options' => [
                'toolName' => 'query',
                'toolArgs' => [
                    'connection' => 'local',
                    'query' => 'SELECT * FROM users LIMIT 1',
                ],
                'envVars' => [
                    'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                ],
            ],
            'testName' => 'select_local',
        ];

        $tests['INSERT Blocked - local'] = [
            'method' => 'tools/call',
            'options' => [
                'toolName' => 'query',
                'toolArgs' => [
                    'connection' => 'local',
                    'query' => 'INSERT INTO users (name, email) VALUES (\'test\', \'test@example.com\')',
                ],
                'envVars' => [
                    'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                ],
            ],
            'testName' => 'insert_blocked',
        ];

        $tests['UPDATE Blocked - local'] = [
            'method' => 'tools/call',
            'options' => [
                'toolName' => 'query',
                'toolArgs' => [
                    'connection' => 'local',
                    'query' => 'UPDATE users SET name = \'hacked\' WHERE id = 1',
                ],
                'envVars' => [
                    'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                ],
            ],
            'testName' => 'update_blocked',
        ];

        $tests['DELETE Blocked - local'] = [
            'method' => 'tools/call',
            'options' => [
                'toolName' => 'query',
                'toolArgs' => [
                    'connection' => 'local',
                    'query' => 'DELETE FROM users WHERE id = 1',
                ],
                'envVars' => [
                    'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                ],
            ],
            'testName' => 'delete_blocked',
        ];

        $tests['DROP Blocked - local'] = [
            'method' => 'tools/call',
            'options' => [
                'toolName' => 'query',
                'toolArgs' => [
                    'connection' => 'local',
                    'query' => 'DROP TABLE users',
                ],
                'envVars' => [
                    'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                ],
            ],
            'testName' => 'drop_blocked',
        ];

        return $tests;
    }

    protected function getSnapshotFilePath(string $method, ?string $testName = null): string
    {
        $methodSlug = str_replace('/', '_', $method);
        $testSlug = $testName ? '_'.$testName : '';

        return \sprintf(
            '%s/tests/Inspector/__snapshots__/QueryToolReadOnly/%s%s.json',
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

    private function initializeTestDatabases(): void
    {
        $_ENV['DATABASE_CONFIG_FILE'] = \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2));

        $logger = new \Psr\Log\NullLogger();
        $loader = new \App\Service\DoctrineConfigLoader($logger);
        $loader->loadAndValidate();

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
