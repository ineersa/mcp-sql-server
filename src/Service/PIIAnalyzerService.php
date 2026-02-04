<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\PIILabel;
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
    private const int BATCH_SIZE = 128;

    private ?\GlinerWrapper $gliner = null;

    public function __construct(
        private LoggerInterface $logger,
        private DoctrineConfigLoader $configLoader,
    ) {
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
        if ([] === $data || [] === $columns) {
            return ['results' => [], 'samples' => []];
        }

        $this->ensureModelLoaded();

        $columnTexts = $this->collectColumnTexts($columns, $data);
        $labels = PIILabel::getAllValues();

        /** @var array<string, list<string>> $results */
        $results = [];
        /** @var array<string, string> $samples */
        $samples = [];

        foreach ($columns as $i => $columnName) {
            $texts = $columnTexts[$i] ?? [];
            if ([] === $texts) {
                continue;
            }

            try {
                $columnLabels = $this->analyzeTexts($texts, $labels, $threshold);

                if ([] !== $columnLabels) {
                    $results[$columnName] = array_keys($columnLabels);
                    sort($results[$columnName]);

                    // Store first sample for this column
                    $samples[$columnName] = substr($texts[array_key_first($columnLabels)] ?? $texts[0], 0, 50);
                }
            } catch (\Throwable $e) {
                $this->logger->error('GLiNER batch prediction failed for column', [
                    'column' => $columnName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['results' => $results, 'samples' => $samples];
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

        $this->ensureModelLoaded();

        $labels = PIILabel::getAllValues();
        $columns = array_keys($rows[0]);

        // Build column-based text arrays for batch processing
        /** @var array<string, list<string>> $columnTexts */
        $columnTexts = [];
        /** @var array<string, list<int>> $columnRowIndices */
        $columnRowIndices = [];

        foreach ($rows as $rowIdx => $row) {
            foreach ($columns as $colName) {
                $value = $row[$colName] ?? null;
                if (null !== $value && '' !== trim((string) $value)) {
                    $columnTexts[$colName][] = (string) $value;
                    $columnRowIndices[$colName][] = $rowIdx;
                }
            }
        }

        $redactedRows = $rows;

        foreach ($columns as $colName) {
            $texts = $columnTexts[$colName] ?? [];
            if ([] === $texts) {
                continue;
            }

            try {
                $redactedTexts = $this->redactTexts($texts, $labels, $threshold);
                $rowIndices = $columnRowIndices[$colName];

                foreach ($redactedTexts as $i => $redactedText) {
                    $redactedRows[$rowIndices[$i]][$colName] = $redactedText;
                }
            } catch (\Throwable $e) {
                $this->logger->error('GLiNER batch redaction failed for column', [
                    'column' => $colName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $redactedRows;
    }

    /**
     * Collect texts from data rows grouped by column index.
     *
     * @param list<string>      $columns Column names
     * @param list<list<mixed>> $data    Row data
     *
     * @return array<int, list<string>> Texts grouped by column index
     */
    private function collectColumnTexts(array $columns, array $data): array
    {
        /** @var array<int, list<string>> $columnTexts */
        $columnTexts = [];
        $numColumns = \count($columns);

        foreach ($data as $row) {
            foreach ($row as $i => $value) {
                if ($i >= $numColumns) {
                    continue;
                }
                $text = null !== $value ? (string) $value : '';
                if ('' !== trim($text)) {
                    $columnTexts[$i][] = $text;
                }
            }
        }

        return $columnTexts;
    }

    /**
     * Analyze texts and return detected labels with sample indices.
     *
     * @param list<string> $texts     Texts to analyze
     * @param list<string> $labels    PII labels to detect
     * @param float        $threshold Confidence threshold
     *
     * @return array<string, int> Map of label => first sample index
     */
    private function analyzeTexts(array $texts, array $labels, float $threshold): array
    {
        /** @var array<string, int> $detectedLabels */
        $detectedLabels = [];

        foreach (array_chunk($texts, self::BATCH_SIZE) as $batchOffset => $batchTexts) {
            $predictions = $this->gliner->predictBatch($batchTexts, $labels);

            foreach ($predictions as $textIdx => $entities) {
                foreach ($entities as $entity) {
                    $score = $entity['score'] ?? 0.0;
                    $label = $entity['label'] ?? '';

                    if ($score >= $threshold && '' !== $label && !isset($detectedLabels[$label])) {
                        $detectedLabels[$label] = ($batchOffset * self::BATCH_SIZE) + $textIdx;
                    }
                }
            }
        }

        return $detectedLabels;
    }

    /**
     * Redact PII from texts.
     *
     * @param list<string> $texts     Texts to redact
     * @param list<string> $labels    PII labels to detect
     * @param float        $threshold Confidence threshold
     *
     * @return list<string> Redacted texts
     */
    private function redactTexts(array $texts, array $labels, float $threshold): array
    {
        $redacted = $texts;

        foreach (array_chunk($texts, self::BATCH_SIZE, true) as $batchOffset => $batchTexts) {
            $batchTextsList = array_values($batchTexts);
            $batchKeys = array_keys($batchTexts);

            $predictions = $this->gliner->predictBatch($batchTextsList, $labels);

            foreach ($predictions as $i => $entities) {
                $filtered = array_filter(
                    $entities,
                    static fn (array $e): bool => $e['score'] >= $threshold
                );

                if ([] === $filtered) {
                    continue;
                }

                // Sort by start position descending to replace from end to start
                usort($filtered, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);

                $text = $batchTextsList[$i];
                foreach ($filtered as $entity) {
                    $start = $entity['start'];
                    $end = $entity['end'];
                    $label = $entity['label'];

                    if ($start < $end) {
                        $text = substr($text, 0, $start).'[REDACTED_'.$label.']'.substr($text, $end);
                    }
                }

                $redacted[$batchKeys[$i]] = $text;
            }
        }

        return array_values($redacted);
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
