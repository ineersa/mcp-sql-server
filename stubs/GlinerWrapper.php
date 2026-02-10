<?php

declare(strict_types=1);

// Stubs for gliner-rs-php extension
// @see https://github.com/ineersa/gliner-rs-php

/**
 * GLiNER Rust-based PHP wrapper for Named Entity Recognition.
 *
 * This class provides an interface to the GLiNER model for detecting
 * PII (Personally Identifiable Information) and other entities in text.
 */
class GlinerWrapper
{
    /**
     * Load the model and tokenizer from the file system.
     *
     * @param string $tokenizer_path Path to tokenizer.json
     * @param string $model_path     Path to model.onnx
     *
     * @throws Exception if model loading fails
     */
    public function __construct(string $tokenizer_path, string $model_path)
    {
    }

    /**
     * Perform prediction on a single text input.
     *
     * @param string   $text   Text to process
     * @param string[] $labels Entity labels to look for
     *
     * @return array<int, array{text: string, label: string, score: float, start: int, end: int, sequence: int}> Array of extracted entities
     */
    public function predictSingle(string $text, array $labels): array
    {
    }

    /**
     * Perform batch prediction.
     *
     * @param string[] $texts  Array of texts to process
     * @param string[] $labels Array of entity labels to look for
     *
     * @return array<int, array<int, array{text: string, label: string, score: float, start: int, end: int, sequence: int}>> Array of extracted entities per text
     */
    public function predictBatch(array $texts, array $labels): array
    {
    }
}
