<?php

declare(strict_types=1);

namespace App\Service;

use App\Utils\TextChunker;
use HelgeSverre\Toon\Toon;
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

    private ?\GlinerWrapper $gliner = null;

    public function __construct(
        private LoggerInterface $logger,
        private DoctrineConfigLoader $configLoader,
    ) {
    }

    /**
     * Redact PII values from query result rows.
     *
     * Strategy:
     * 1. Encode all rows to TOON.
     * 2. If result is small enough, redact directly.
     * 3. If too large, split rows into chunks, encode/redact/decode each chunk, then merge.
     *
     * @param list<array<string, mixed>> $rows Query result rows to redact
     *
     * @return string The redaction TOON-encoded string
     */
    public function redact(array $rows): string
    {
        if ([] === $rows) {
            return '';
        }

        $fullEncoded = Toon::encode($rows);

        // If small enough, just redact the whole string
        if (\strlen($fullEncoded) <= self::MAX_CHUNK_CHARS) {
            return $this->redactString($fullEncoded);
        }

        // --- Large Result Strategy: Chunk by Rows ---
        // We can't just split the TOON string arbitrarily because we need valid structure.
        // So we split the source rows into smaller batches.

        // Estimate rows per chunk (avg row length)
        $avgRowLen = (int) ceil(\strlen($fullEncoded) / \count($rows));
        // Target ~1500 chars to be safe (under 1800)
        $rowsPerChunk = (int) floor(1500 / $avgRowLen);
        if ($rowsPerChunk < 1) {
            $rowsPerChunk = 1;
        }

        $chunks = array_chunk($rows, $rowsPerChunk);
        $redactedRows = [];

        foreach ($chunks as $chunkRows) {
            $chunkEncoded = Toon::encode($chunkRows);
            // Redact this chunk's TOON string
            $redactedChunkStr = $this->redactString($chunkEncoded);

            // Decode back to get the redacted structure
            try {
                $decoded = Toon::decode($redactedChunkStr);
                if (\is_array($decoded)) {
                    foreach ($decoded as $r) {
                        $redactedRows[] = $r;
                    }
                }
            } catch (\Throwable $e) {
                // If decoding fails (e.g. redaction broke syntax), fallback to original rows for this chunk?
                // Or log error. Ideally we want to fail safe.
                $this->logger->error('Failed to decode redacted chunk', ['error' => $e->getMessage()]);
                throw $e;
            }
        }

        return Toon::encode($redactedRows);
    }

    private function redactString(string $text): string
    {
        $this->ensureModelLoaded();

        // Use TextChunker to handle large text safely
        $chunks = TextChunker::splitTextSafely($text, self::MAX_CHUNK_CHARS);

        try {
            $labels = $this->configLoader->getLabels();
            $threshold = $this->configLoader->getThreshold();

            $batchPredictions = $this->gliner->predictBatch($chunks, $labels);

            $redactedChunks = [];

            foreach ($chunks as $idx => $chunkText) {
                $predictions = $batchPredictions[$idx] ?? [];

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

            return implode('', $redactedChunks);
        } catch (\Throwable $e) {
            $this->logger->error('GLiNER redaction failed', ['error' => $e->getMessage()]);

            return $text;
        }
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
