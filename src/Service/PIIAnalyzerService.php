<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Manages Python GLiNER subprocess for PII detection.
 *
 * Communicates via NDJSON (line-delimited JSON) on stdin/stdout.
 */
final class PIIAnalyzerService
{
    private const SCRIPT_PATH = 'scripts/gliner_pii.py';

    /** @var resource|null The process resource from proc_open */
    private $process;

    /** @var resource|null */
    private $stdin;

    /** @var resource|null */
    private $stdout;

    /** @var resource|null */
    private $stderr;

    private bool $isReady = false;

    public function __construct(
        private LoggerInterface $logger,
        private string $pythonBinary = 'python3',
    ) {
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Start the Python GLiNER subprocess.
     *
     * @throws \RuntimeException if process fails to start or model fails to load
     */
    public function start(bool $waitForReady = true): void
    {
        if (null !== $this->process && \is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                return;
            }
        }

        $scriptPath = $this->getScriptPath();

        if (!file_exists($scriptPath)) {
            throw new \RuntimeException(\sprintf('GLiNER script not found: %s', $scriptPath));
        }

        $this->logger->info('Starting GLiNER PII analyzer...');

        // Start the process with pipes for stdin/stdout
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $proc = proc_open(
            [$this->pythonBinary, $scriptPath],
            $descriptors,
            $pipes,
            \dirname($scriptPath, 2)
        );

        if (!\is_resource($proc)) {
            throw new \RuntimeException('Failed to start GLiNER Python process');
        }

        $this->process = $proc;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];

        // Make stdout non-blocking for reading with timeout
        stream_set_blocking($this->stdout, false);

        if ($waitForReady) {
            $this->waitForReady();
        } else {
            $this->logger->info('GLiNER started in background, waiting for readiness...');
        }
    }

    /**
     * Analyze table data for PII.
     *
     * @param string            $tableName Name of the table being analyzed
     * @param list<string>      $columns   Column names
     * @param list<list<mixed>> $data      Row data (array of rows, each row is array of values)
     * @param float             $threshold Confidence threshold (0.0-1.0)
     *
     * @return array{results: array<string, list<string>>, samples: array<string, string>}
     *
     * @throws \RuntimeException if analysis fails
     */
    public function analyze(string $tableName, array $columns, array $data, float $threshold = 0.9): array
    {
        $this->waitForReady();

        if (null === $this->stdin || null === $this->stdout || !\is_resource($this->stdin) || !\is_resource($this->stdout)) {
            throw new \RuntimeException('GLiNER process not started or not running. Call start() first.');
        }

        $request = [
            'action' => 'analyze',
            'table' => $tableName,
            'columns' => $columns,
            'data' => $data,
            'threshold' => $threshold,
        ];

        $this->writeLine(json_encode($request, \JSON_THROW_ON_ERROR));

        // Wait for response (no timeout - user can Ctrl+C if needed)
        while (true) {
            $line = $this->readLine(1);

            if (null !== $line) {
                $response = json_decode($line, true);

                if (!\is_array($response)) {
                    throw new \RuntimeException('Invalid JSON response from GLiNER');
                }

                if (isset($response['error'])) {
                    throw new \RuntimeException('GLiNER analysis error: '.$response['error']);
                }

                return [
                    'results' => $response['results'] ?? [],
                    'samples' => $response['samples'] ?? [],
                ];
            }

            usleep(50000); // 50ms
        }
    }

    /**
     * Redact PII values from query result rows.
     *
     * @param list<array<string, mixed>> $rows      Query result rows to redact
     * @param float                      $threshold Confidence threshold (0.0-1.0)
     *
     * @return list<array<string, mixed>> Rows with PII values replaced by [REDACTED_type]
     *
     * @throws \RuntimeException if redaction fails
     */
    public function redact(array $rows, float $threshold = 0.9): array
    {
        if ([] === $rows) {
            return [];
        }

        $this->waitForReady();

        if (null === $this->stdin || null === $this->stdout || !\is_resource($this->stdin) || !\is_resource($this->stdout)) {
            throw new \RuntimeException('GLiNER process not started or not running. Call start() first.');
        }

        $columns = array_keys($rows[0]);
        $data = array_map('array_values', $rows);

        $request = [
            'action' => 'redact',
            'columns' => $columns,
            'data' => $data,
            'threshold' => $threshold,
        ];

        $this->writeLine(json_encode($request, \JSON_THROW_ON_ERROR));

        while (true) {
            $line = $this->readLine(1);

            if (null !== $line) {
                $response = json_decode($line, true);

                if (!\is_array($response)) {
                    throw new \RuntimeException('Invalid JSON response from GLiNER');
                }

                if (isset($response['error'])) {
                    throw new \RuntimeException('GLiNER redaction error: '.$response['error']);
                }

                $redactedData = $response['data'] ?? [];
                $result = [];

                foreach ($redactedData as $rowData) {
                    $row = [];
                    foreach ($columns as $i => $col) {
                        $row[$col] = $rowData[$i] ?? null;
                    }
                    $result[] = $row;
                }

                return $result;
            }

            usleep(50000);
        }
    }

    /**
     * Stop the Python subprocess gracefully.
     */
    public function stop(): void
    {
        if (null !== $this->stdin && \is_resource($this->stdin)) {
            try {
                $this->writeLine(json_encode(['action' => 'shutdown']));
            } catch (\Throwable) {
                // Ignore write errors during shutdown
            }

            fclose($this->stdin);
        }
        $this->stdin = null;

        if (null !== $this->stdout && \is_resource($this->stdout)) {
            fclose($this->stdout);
        }
        $this->stdout = null;

        $this->closeStderr();

        if (null !== $this->process && \is_resource($this->process)) {
            proc_close($this->process);
        }
        $this->process = null;

        $this->logger->info('GLiNER PII analyzer stopped');
    }

    /**
     * Check if the analyzer is running.
     */
    public function isRunning(): bool
    {
        return null !== $this->stdin && null !== $this->stdout;
    }

    private function waitForReady(): void
    {
        if ($this->isReady) {
            return;
        }

        $this->logger->info('Waiting for GLiNER to become ready...');

        // Wait for ready signal (no timeout - model download may take a while, user can Ctrl+C)
        while (true) {
            $line = $this->readLine(1);

            if (null !== $line) {
                $response = json_decode($line, true);

                if (\is_array($response)) {
                    if (isset($response['error'])) {
                        $stderrContent = stream_get_contents($this->stderr);
                        $this->closeStderr();
                        throw new \RuntimeException('GLiNER error: '.$response['error'].($stderrContent ? "\n".$stderrContent : ''));
                    }

                    if (isset($response['status'])) {
                        if ('ready' === $response['status']) {
                            $this->isReady = true;
                            break;
                        }
                        if ('loading' === $response['status']) {
                            $this->logger->info('GLiNER model is loading...');
                        }
                        if ('downloading' === $response['status']) {
                            $this->logger->info('GLiNER model is downloading (first run may take a while)...');
                        }
                    }
                }
            }

            usleep(100000); // 100ms
        }

        $this->closeStderr();
        $this->logger->info('GLiNER PII analyzer ready');
    }

    private function closeStderr(): void
    {
        if (null !== $this->stderr && \is_resource($this->stderr)) {
            fclose($this->stderr);
        }
        $this->stderr = null;
    }

    private function getScriptPath(): string
    {
        // Check relative to project root
        $projectRoot = \dirname(__DIR__, 2);

        return $projectRoot.'/'.self::SCRIPT_PATH;
    }

    private function writeLine(string $data): void
    {
        if (null === $this->stdin || !\is_resource($this->stdin)) {
            throw new \RuntimeException('Process stdin not available');
        }

        $written = fwrite($this->stdin, $data."\n");

        if (false === $written) {
            throw new \RuntimeException('Failed to write to GLiNER process');
        }

        fflush($this->stdin);
    }

    private function readLine(int $timeoutSeconds): ?string
    {
        if (null === $this->stdout || !\is_resource($this->stdout)) {
            return null;
        }

        $startTime = time();

        while ((time() - $startTime) < $timeoutSeconds) {
            $line = fgets($this->stdout);

            if (false !== $line && '' !== trim($line)) {
                return trim($line);
            }

            usleep(10000); // 10ms
        }

        return null;
    }
}
