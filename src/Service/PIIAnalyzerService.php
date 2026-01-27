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
    public function start(): void
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

        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $stderr = $pipes[2];

        // Make stdout non-blocking for reading with timeout
        stream_set_blocking($this->stdout, false);

        // Wait for ready signal (no timeout - model download may take a while, user can Ctrl+C)
        $isReady = false;

        while (true) {
            $line = $this->readLine(1);

            if (null !== $line) {
                $response = json_decode($line, true);

                if (\is_array($response)) {
                    if (isset($response['error'])) {
                        $stderrContent = stream_get_contents($stderr);
                        fclose($stderr);
                        throw new \RuntimeException('GLiNER error: '.$response['error'].($stderrContent ? "\n".$stderrContent : ''));
                    }

                    if (isset($response['status'])) {
                        if ('ready' === $response['status']) {
                            $isReady = true;
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

        fclose($stderr);

        if (!$isReady) {
            proc_terminate($proc);
            throw new \RuntimeException('GLiNER process failed to become ready');
        }

        // Store the process resource to keep it alive
        $this->process = $proc;
        $this->logger->info('GLiNER PII analyzer ready');
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
