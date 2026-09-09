<?php

declare(strict_types=1);

namespace App\Tests\Inspector;

final class QueryToolSelectTest extends InspectorSnapshotTestCase
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
        $baseTests = [
            'Tool Listing' => ['method' => 'tools/list'],
        ];

        foreach (['local', 'products', 'users', 'server'] as $connection) {
            $query = 'SELECT * FROM users ORDER BY id LIMIT 10';
            if ('server' === $connection) {
                $query = 'SELECT TOP 10 * FROM users ORDER BY id';
            }

            $baseTests[\sprintf('Query Execution - %s', $connection)] = [
                'method' => 'tools/call',
                'options' => [
                    'toolName' => 'query',
                    'toolArgs' => [
                        'connection' => $connection,
                        'query' => $query,
                    ],
                    'envVars' => [
                        'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                    ],
                ],
                'testName' => $connection,
            ];
        }

        // Add truncation tests (using local connection)
        $baseTests['Query Truncation - Multi Row'] = [
            'method' => 'tools/call',
            'options' => [
                'toolName' => 'query',
                'toolArgs' => [
                    'connection' => 'local',
                    'query' => 'SELECT * FROM pii_samples ORDER BY id LIMIT 10',
                ],
                'envVars' => [
                    'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                ],
            ],
            'testName' => 'truncation_multi',
        ];

        $baseTests['Query Truncation - Single Row'] = [
            'method' => 'tools/call',
            'options' => [
                'toolName' => 'query',
                'toolArgs' => [
                    'connection' => 'local',
                    'query' => 'SELECT * FROM pii_samples WHERE id = 1',
                ],
                'envVars' => [
                    'DATABASE_CONFIG_FILE' => \sprintf('%s/databases.test.yaml', \dirname(__DIR__, 2)),
                ],
            ],
            'testName' => 'truncation_single',
        ];

        return $baseTests;
    }

    public function testNormalizesOnlyConnectionVersionsInQueryToolDescription(): void
    {
        $output = <<<'JSON'
{"tools":[{"name":"query","description":"Query.\nAvailable connections:\n - products : MySQL, version 8.0 [PII GUARDED]\n - users : Postgres, version 16.15 (Debian build)\n - server : SQL Server, version 2019"},{"name":"other","description":"Version 16.15"}]}
JSON;

        $expected = <<<'JSON'
{"tools":[{"name":"query","description":"Query.\nAvailable connections:\n - products : MySQL, version <VERSION> [PII GUARDED]\n - users : Postgres, version <VERSION>\n - server : SQL Server, version <VERSION>"},{"name":"other","description":"Version 16.15"}]}
JSON;

        $this->assertSame($expected, $this->normalizeTestOutput($output));
    }

    protected function getSnapshotFilePath(string $method, ?string $testName = null): string
    {
        $methodSlug = str_replace('/', '_', $method);
        $testSlug = $testName ? '_'.$testName : '';

        return \sprintf(
            '%s/tests/Inspector/__snapshots__/QueryToolSelect/%s%s.json',
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

    protected function normalizeTestOutput(string $output, ?string $testName = null): string
    {
        $response = json_decode($output, true);
        if (!\is_array($response) || !isset($response['tools']) || !\is_array($response['tools'])) {
            return $output;
        }

        foreach ($response['tools'] as &$tool) {
            if (!\is_array($tool) || 'query' !== ($tool['name'] ?? null) || !isset($tool['description']) || !\is_string($tool['description'])) {
                continue;
            }

            $tool['description'] = preg_replace(
                '#(\\n - [^\\n]+ : [^,\\n]+, version )([^\\n[]+)( \\[[^\\]]+\\])?(?=\\n|$)#',
                '$1<VERSION>$3',
                $tool['description'],
            ) ?? $tool['description'];
        }
        unset($tool);

        return json_encode($response, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
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
