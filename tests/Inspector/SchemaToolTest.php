<?php

declare(strict_types=1);

namespace App\Tests\Inspector;

final class SchemaToolTest extends InspectorSnapshotTestCase
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
        $baseTests = [];

        foreach (['local', 'products'] as $connection) {
            $baseTests[\sprintf('Schema Tool - %s (Basic)', $connection)] = [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'schema',
                    'toolArgs' => [
                        'connection' => $connection,
                        'filter' => 'users',
                    ],
                    'envVars' => [
                        'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                    ],
                ],
                'testName' => $connection.'_basic',
            ];

            $baseTests[\sprintf('Schema Tool - %s (With Views and Routines)', $connection)] = [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'schema',
                    'toolArgs' => [
                        'connection' => $connection,
                        'filter' => 'users',
                        'detail' => 'full',
                        'includeViews' => true,
                        'includeRoutines' => true,
                    ],
                    'envVars' => [
                        'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                    ],
                ],
                'testName' => $connection.'_full',
            ];

            $baseTests[\sprintf('Schema Tool - %s (Columns Detail)', $connection)] = [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'schema',
                    'toolArgs' => [
                        'connection' => $connection,
                        'filter' => 'users',
                        'detail' => 'columns',
                    ],
                    'envVars' => [
                        'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                    ],
                ],
                'testName' => $connection.'_columns',
            ];

            $baseTests[\sprintf('Schema Tool - %s (Columns + Glob Match)', $connection)] = [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'schema',
                    'toolArgs' => [
                        'connection' => $connection,
                        'filter' => 'user*',
                        'detail' => 'columns',
                        'matchMode' => 'glob',
                    ],
                    'envVars' => [
                        'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                    ],
                ],
                'testName' => $connection.'_columns_glob',
            ];

            $baseTests[\sprintf('Schema Tool - %s (With Match Mode)', $connection)] = [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'schema',
                    'toolArgs' => [
                        'connection' => $connection,
                        'filter' => 'use',
                        'detail' => 'summary',
                        'matchMode' => 'prefix',
                        'includeViews' => true,
                        'includeRoutines' => true,
                    ],
                    'envVars' => [
                        'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                    ],
                ],
                'testName' => $connection.'_filter',
            ];
        }

        return $baseTests;
    }

    protected function getSnapshotFilePath(string $method, ?string $testName = null): string
    {
        $methodSlug = str_replace('/', '_', $method);
        $testSlug = $testName ? '_'.$testName : '';

        return \sprintf(
            '%s/tests/Inspector/__snapshots__/SchemaTool/%s%s.json',
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
        $modelDownloader = new \App\Service\ModelDownloaderService($logger);
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
