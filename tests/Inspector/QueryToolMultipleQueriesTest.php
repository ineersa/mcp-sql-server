<?php

declare(strict_types=1);

namespace App\Tests\Inspector;

final class QueryToolMultipleQueriesTest extends InspectorSnapshotTestCase
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
        return [
            'Multiple Queries' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'query',
                    'toolArgs' => [
                        'connection' => 'local',
                        'query' => 'SELECT * FROM users WHERE id = 1; SELECT count(*) as count FROM users',
                    ],
                    'envVars' => [
                        'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                    ],
                ],
                'testName' => 'multiple_queries',
            ],
            'Semicolon In Quotes' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'query',
                    'toolArgs' => [
                        'connection' => 'local',
                        'query' => "SELECT ';' LIMIT 1; SELECT \"'\" LIMIT 1",
                    ],
                    'envVars' => [
                        'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                    ],
                ],
                'testName' => 'semicolon_in_quotes',
            ],
            'Escaped Semicolon' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'query',
                    'toolArgs' => [
                        'connection' => 'local',
                        'query' => "SELECT 'It''s a test' LIMIT 1",
                    ],
                    'envVars' => [
                        'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                    ],
                ],
                'testName' => 'escaped_quotes',
            ],
            'Partial Failure' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'query',
                    'toolArgs' => [
                        'connection' => 'local',
                        'query' => 'SELECT 1 as val LIMIT 1; SELECT * FROM non_existent_table LIMIT 1; SELECT 2 as val LIMIT 1',
                    ],
                    'envVars' => [
                        'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                    ],
                ],
                'testName' => 'partial_failure',
            ],
            'Comments' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'query',
                    'toolArgs' => [
                        'connection' => 'local',
                        'query' => "SELECT 1 as val LIMIT 1; -- comment\nSELECT 2 as val LIMIT 1 /* block comment */; \n# hash comment\nSELECT 3 as val LIMIT 1",
                    ],
                    'envVars' => [
                        'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                    ],
                ],
                'testName' => 'comments',
            ],
            'Comments In Quotes' => [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'query',
                    'toolArgs' => [
                        'connection' => 'local',
                        'query' => "SELECT '-- not a comment' as val LIMIT 1; SELECT '/* not a comment */' as val2 LIMIT 1",
                    ],
                    'envVars' => [
                        'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                    ],
                ],
                'testName' => 'comments_in_quotes',
            ],
        ];
    }

    protected function getSnapshotFilePath(string $method, ?string $testName = null): string
    {
        $methodSlug = str_replace('/', '_', $method);
        $testSlug = $testName ? '_'.$testName : '';

        return \sprintf(
            '%s/tests/Inspector/__snapshots__/QueryToolMultipleQueries/%s%s.json',
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
                continue;
            }
        }
    }
}
