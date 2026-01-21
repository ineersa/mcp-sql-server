<?php

declare(strict_types=1);

namespace App\Tests\Inspector;

use Mcp\Schema\Enum\LoggingLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[\PHPUnit\Framework\Attributes\CoversNothing]
abstract class InspectorSnapshotTestCase extends TestCase
{
    private const INSPECTOR_VERSION = '0.18.0'; // Latest version as of 2026-01-09

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
        ];

        // Add transport if not stdio (stdio is default)
        $transport = $this->getTransport();
        if ('stdio' !== $transport) {
            $args[] = '--transport';
            $args[] = $transport;
        }

        $args[] = '--method';
        $args[] = $method;

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
            $this->fail(\sprintf("Inspector failed: %s\nOutput: %s\nError Output: %s", $e->getMessage(), $process->getOutput(), $process->getErrorOutput()));
        }

        $snapshotFile = $this->getSnapshotFilePath($method, $testName);

        // Ensure directory exists
        $dir = \dirname($snapshotFile);
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

    /**
     * @return array<string, array{method: string, options?: array<string, mixed>, testName?: string|null}>
     */
    public static function provideMethods(): array
    {
        return [
            'Prompt Listing' => ['method' => 'prompts/list'],

            'Tool Listing' => ['method' => 'tools/list'],
        ];
    }

    protected function normalizeTestOutput(string $output, ?string $testName = null): string
    {
        return $output;
    }

    abstract protected function getSnapshotFilePath(string $method, ?string $testName = null): string;

    /** @return array<string> */
    abstract protected function getServerConnectionArgs(): array;

    abstract protected function getTransport(): string;
}
