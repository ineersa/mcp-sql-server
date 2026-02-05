<?php

declare(strict_types=1);

namespace App\Service;

use App\Utils\TextChunker;
use Psr\Log\LoggerInterface;

/**
 * Manages GLiNER PII detection using the native PHP extension.
 *
 * Uses the gliner-rs-php extension for fast ONNX-based inference.
 *
 * @see https://github.com/ineersa/gliner-rs-php
 */
final class PIIAnalyzerService
{
    /**
     * Max characters per chunk for GLiNER inference.
     * 1800 chars is ~450 tokens, leaving room for safety within standard 512 limit.
     */
    private const int MAX_CHUNK_CHARS = 1800;

    // Delimiters for concatenation
    private const string COL_SEP = ' <||SEP||> ';
    private const string ROW_SEP = ' <||ROW_SEP||> ';

    private ?\GlinerWrapper $gliner = null;

    public function __construct(
        private LoggerInterface $logger,
        private DoctrineConfigLoader $configLoader,
    ) {
    }

    /**
     * Redact PII values from query result rows.
     *
     * Simplified Strategy:
     * 1. Concatenate ALL rows into ONE big string.
     * 2. Split string into chunks of 2500 chars.
     * 3. Send chunks to GLiNER in ONE batch call.
     * 4. Join redacted chunks and explode back to rows.
     *
     * @param list<array<string, mixed>> $rows Query result rows to redact
     *
     * @return list<array<string, mixed>> Rows with PII values replaced by [REDACTED_type]
     */
    public function redact(array $rows): array
    {
        if ([] === $rows) {
            return [];
        }

        $this->ensureModelLoaded();

        $columns = array_keys($rows[0]);

        // 1. One Big String
        $rowStrings = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $col) {
                $values[] = $this->valueToString($row[$col] ?? null);
            }
            $rowStrings[] = implode(self::COL_SEP, $values);
        }
        $fullText = implode(self::ROW_SEP, $rowStrings);

        // 2. Safe Split
        $textChunks = TextChunker::splitTextSafely(
            $fullText,
            self::MAX_CHUNK_CHARS,
            self::ROW_SEP,
            self::COL_SEP
        );

        // 3. Run Inference (One Single Call)
        try {
            $labels = $this->configLoader->getLabels();
            $threshold = $this->configLoader->getThreshold();

            /** @var list<list<array{start: int, end: int, label: string, score: float}>> $batchPredictions */
            $batchPredictions = $this->gliner->predictBatch($textChunks, $labels);

            $redactedChunks = [];

            foreach ($textChunks as $chunkIdx => $chunkText) {
                $predictions = $batchPredictions[$chunkIdx] ?? [];

                $filtered = array_filter(
                    $predictions,
                    static fn (array $e): bool => $e['score'] >= $threshold
                );

                if ([] !== $filtered) {
                    usort($filtered, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
                    foreach ($filtered as $entity) {
                        $start = $entity['start'];
                        $end = $entity['end'];
                        $label = $entity['label'];
                        if ($start < $end) {
                            $chunkText = substr($chunkText, 0, $start).'[REDACTED_'.$label.']'.substr($chunkText, $end);
                        }
                    }
                }
                $redactedChunks[] = $chunkText;
            }

            // 4. Join
            $fullRedactedText = implode('', $redactedChunks);

            // 5. Explode back to rows
            $finalRows = [];
            $chunkRowStrings = explode(self::ROW_SEP, $fullRedactedText);

            foreach ($chunkRowStrings as $rowString) {
                $rowValues = explode(self::COL_SEP, $rowString);
                $newRow = [];
                foreach ($columns as $i => $colName) {
                    $newRow[$colName] = $rowValues[$i] ?? '';
                }
                $finalRows[] = $newRow;
            }

            // Safety: Match row counts
            $parsedCount = \count($finalRows);
            $totalCount = \count($rows);

            if ($parsedCount < $totalCount) {
                for ($i = $parsedCount; $i < $totalCount; ++$i) {
                    $safeRow = [];
                    foreach ($columns as $col) {
                        $safeRow[$col] = $this->valueToString($rows[$i][$col] ?? null);
                    }
                    $finalRows[] = $safeRow;
                }
            }
            if ($parsedCount > $totalCount) {
                $finalRows = \array_slice($finalRows, 0, $totalCount);
            }

            return $finalRows;
        } catch (\Throwable $e) {
            $this->logger->error('GLiNER batch redaction failed', [
                'error' => $e->getMessage(),
                'chunkCount' => \count($textChunks),
            ]);
            throw $e;
        }
    }

    /**
     * Convert value to string representation for concatenation.
     */
    private function valueToString(mixed $value): string
    {
        if (null === $value) {
            return 'NULL';
        }
        if (true === $value) {
            return 'true';
        }
        if (false === $value) {
            return 'false';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $str = (string) $value;

        // Sanitize delimiters to prevent collision/injection
        return str_replace(
            [self::COL_SEP, self::ROW_SEP],
            [' [SEP] ', ' [ROW_SEP] '],
            $str
        );
    }

    /**
     * Ensure the GLiNER model is loaded.
     *
     * @throws \RuntimeException if model loading fails
     */
    private function ensureModelLoaded(): void
    {
        if (null !== $this->gliner) {
            return;
        }

        if (!class_exists(\GlinerWrapper::class)) {
            throw new \RuntimeException('GLiNER PHP extension not installed. Install from: https://github.com/ineersa/gliner-rs-php');
        }

        $tokenizerPath = $this->configLoader->getTokenizerPath();
        $modelPath = $this->configLoader->getModelPath();

        if (null === $tokenizerPath || null === $modelPath) {
            throw new \RuntimeException('PII config not set. Add pii.tokenizer_path and pii.model_path to your database config.');
        }

        if (!file_exists($tokenizerPath)) {
            throw new \RuntimeException(\sprintf('Tokenizer file not found: %s', $tokenizerPath));
        }

        if (!file_exists($modelPath)) {
            throw new \RuntimeException(\sprintf('Model file not found: %s', $modelPath));
        }

        $this->logger->info('Loading GLiNER model...', [
            'tokenizer' => $tokenizerPath,
            'model' => $modelPath,
        ]);

        try {
            $this->gliner = new \GlinerWrapper($tokenizerPath, $modelPath);
            $this->logger->info('GLiNER PII analyzer ready');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to load GLiNER model: '.$e->getMessage(), previous: $e);
        }
    }
}
