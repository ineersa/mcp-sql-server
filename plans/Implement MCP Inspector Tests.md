# Implement MCP Inspector Tests

## Goal
Add MCP Inspector tests to verify server startup and tool discovery using the `npx @modelcontextprotocol/inspector`.

## Prerequisites
- Node.js and `npx` installed.
- `symfony/process` package (needs to be installed).

## Proposed Changes

### 1. Install Dependencies
We need `symfony/process` to run the inspector command.
```bash
composer require --dev symfony/process
```

### 2. Create Base Test Case
**File:** `tests/Inspector/InspectorSnapshotTestCase.php`

This class handles running the inspector via `npx` and asserting the output matches the snapshot.

```php
<?php

namespace App\Tests\Inspector;

use Mcp\Schema\Enum\LoggingLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

abstract class InspectorSnapshotTestCase extends TestCase
{
    private const INSPECTOR_VERSION = '0.4.1'; // Check latest version if needed

    /** @param array<string, mixed> $options */
    #[DataProvider('provideMethods')]
    public function testOutputMatchesSnapshot(
        string $method,
        array $options = [],
        ?string $testName = null,
    ): void {
        $inspector = \sprintf('@modelcontextprotocol/inspector@%s', self::INSPECTOR_VERSION);

        $args = [
            'npx',
            '-y', // Auto confirm install
            $inspector,
            '--cli',
            ...$this->getServerConnectionArgs(),
            '--transport',
            $this->getTransport(),
            '--method',
            $method,
        ];

        // Options for tools/call
        if (isset($options['toolName'])) {
            $args[] = '--tool-name';
            $args[] = $options['toolName'];

            foreach ($options['toolArgs'] ?? [] as $key => $value) {
                $args[] = '--tool-arg';
                if (\is_array($value)) {
                    $args[] = \sprintf('%s=%s', $key, json_encode($value));
                } elseif (\is_bool($value)) {
                    $args[] = \sprintf('%s=%s', $key, $value ? '1' : '0');
                } else {
                    $args[] = \sprintf('%s=%s', $key, $value);
                }
            }
        }

        // Options for resources/read
        if (isset($options['uri'])) {
            $args[] = '--uri';
            $args[] = $options['uri'];
        }

        // Options for prompts/get
        if (isset($options['promptName'])) {
            $args[] = '--prompt-name';
            $args[] = $options['promptName'];

            foreach ($options['promptArgs'] ?? [] as $key => $value) {
                $args[] = '--prompt-args';
                if (\is_array($value)) {
                    $args[] = \sprintf('%s=%s', $key, json_encode($value));
                } elseif (\is_bool($value)) {
                    $args[] = \sprintf('%s=%s', $key, $value ? '1' : '0');
                } else {
                    $args[] = \sprintf('%s=%s', $key, $value);
                }
            }
        }

        // Options for logging/setLevel
        if (isset($options['logLevel'])) {
            $args[] = '--log-level';
            $args[] = $options['logLevel'] instanceof LoggingLevel ? $options['logLevel']->value : $options['logLevel'];
        }

        // Options for env variables
        if (isset($options['envVars'])) {
            foreach ($options['envVars'] as $key => $value) {
                $args[] = '-e';
                $args[] = \sprintf('%s=%s', $key, $value);
            }
        }

        $process = new Process(command: $args);
        $process->setTimeout(60); // Give npx some time
        
        try {
            $process->mustRun();
            $output = $process->getOutput();
        } catch (\Exception $e) {
            $this->fail(sprintf("Inspector failed: %s\nOutput: %s\nError Output: %s", $e->getMessage(), $process->getOutput(), $process->getErrorOutput()));
        }

        $snapshotFile = $this->getSnapshotFilePath($method, $testName);
        
        // Ensure directory exists
        $dir = dirname($snapshotFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $normalizedOutput = $this->normalizeTestOutput($output, $testName);

        if (!file_exists($snapshotFile)) {
            file_put_contents($snapshotFile, $normalizedOutput.\PHP_EOL);
            $this->markTestIncomplete("Snapshot created at $snapshotFile, please re-run tests.");
        }

        $expected = file_get_contents($snapshotFile);

        $message = \sprintf('Output does not match snapshot "%s".', $snapshotFile);
        $this->assertJsonStringEqualsJsonString($expected, $normalizedOutput, $message);
    }

    protected function normalizeTestOutput(string $output, ?string $testName = null): string
    {
        return $output;
    }

    public static function provideMethods(): array
    {
        return [
            'Prompt Listing' => ['method' => 'prompts/list'],
            'Resource Listing' => ['method' => 'resources/list'],
            'Resource Template Listing' => ['method' => 'resources/templates/list'],
            'Tool Listing' => ['method' => 'tools/list'],
        ];
    }

    abstract protected function getSnapshotFilePath(string $method, ?string $testName = null): string;

    /** @return array<string> */
    abstract protected function getServerConnectionArgs(): array;

    abstract protected function getTransport(): string;
}
```

### 3. Create Server Test
**File:** `tests/Inspector/DatabaseMcpServerTest.php`

```php
<?php

namespace App\Tests\Inspector;

use PHPUnit\Framework\Attributes\Test;

class DatabaseMcpServerTest extends InspectorSnapshotTestCase
{
    private string $snapshotDir;

    protected function setUp(): void
    {
        $this->snapshotDir = dirname(__DIR__, 2) . '/tests/__snapshots__';
    }

    protected function getSnapshotFilePath(string $method, ?string $testName = null): string
    {
        $name = $testName ?? preg_replace('#[^a-zA-Z0-9]+#', '_', $method);
        return $this->snapshotDir . '/' . $name . '.json';
    }

    protected function getServerConnectionArgs(): array
    {
        return [
            PHP_BINARY,
            dirname(__DIR__, 2) . '/bin/database-mcp',
        ];
    }

    protected function getTransport(): string
    {
        return 'stdio';
    }
}
```

## Task List
- [ ] Install `symfony/process`: `composer require --dev symfony/process`
- [ ] Create `tests/Inspector/InspectorSnapshotTestCase.php`
- [ ] Create `tests/Inspector/DatabaseMcpServerTest.php`
- [ ] Create snapshot directory if not auto-created
- [ ] Run tests: `vendor/bin/phpunit tests/Inspector`
