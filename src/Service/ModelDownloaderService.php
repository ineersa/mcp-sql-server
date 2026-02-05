<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Downloads GLiNER model files from Hugging Face.
 *
 * Automatically downloads tokenizer.json and model.onnx files
 * if they don't exist locally.
 */
final class ModelDownloaderService
{
    private const string HUGGINGFACE_BASE_URL = 'https://huggingface.co/ineersa/gliner-PII-onnx/resolve/main';
    private const string TOKENIZER_FILENAME = 'tokenizer.json';
    private const string MODEL_FILENAME = 'model.onnx';

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Ensure model files exist, downloading them if necessary.
     *
     * @param string $tokenizerPath Path where tokenizer.json should be
     * @param string $modelPath     Path where model.onnx should be
     *
     * @throws \RuntimeException if download fails
     */
    public function ensureModelsExist(string $tokenizerPath, string $modelPath): void
    {
        $this->ensureFileExists(
            $tokenizerPath,
            self::HUGGINGFACE_BASE_URL.'/'.self::TOKENIZER_FILENAME.'?download=true',
            'tokenizer'
        );

        $this->ensureFileExists(
            $modelPath,
            self::HUGGINGFACE_BASE_URL.'/'.self::MODEL_FILENAME.'?download=true',
            'model'
        );
    }

    /**
     * Check if both model files exist.
     */
    public function modelsExist(string $tokenizerPath, string $modelPath): bool
    {
        return file_exists($tokenizerPath) && file_exists($modelPath);
    }

    /**
     * Ensure a file exists, downloading it if necessary.
     *
     * @param string $localPath   Local path for the file
     * @param string $downloadUrl URL to download from
     * @param string $fileType    Type description for logging
     *
     * @throws \RuntimeException if download fails
     */
    private function ensureFileExists(string $localPath, string $downloadUrl, string $fileType): void
    {
        if (file_exists($localPath)) {
            $this->logger->debug('Model file already exists', [
                'type' => $fileType,
                'path' => $localPath,
            ]);

            return;
        }

        $this->downloadFile($downloadUrl, $localPath, $fileType);
    }

    /**
     * Download a file.
     *
     * @param string $url      URL to download from
     * @param string $destPath Destination path
     * @param string $fileType Type description for logging
     *
     * @throws \RuntimeException if download fails
     */
    private function downloadFile(string $url, string $destPath, string $fileType): void
    {
        $this->logger->info('Downloading GLiNER {type} file from Hugging Face...', [
            'type' => $fileType,
            'url' => $url,
            'destination' => $destPath,
        ]);

        // Ensure directory exists
        $dir = \dirname($destPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Failed to create directory: %s', $dir));
        }

        // Download with cURL
        $tempPath = $destPath.'.download';
        $fp = fopen($tempPath, 'w');
        if (false === $fp) {
            throw new \RuntimeException(\sprintf('Failed to open file for writing: %s', $tempPath));
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            \CURLOPT_URL => $url,
            \CURLOPT_FILE => $fp,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 5,
            \CURLOPT_TIMEOUT => 3600, // 1 hour timeout for large files
            \CURLOPT_CONNECTTIMEOUT => 30,
            \CURLOPT_FAILONERROR => true,
            \CURLOPT_USERAGENT => 'database-mcp-server/1.0',
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        fclose($fp);

        if (!$success || 200 !== $httpCode) {
            @unlink($tempPath);
            throw new \RuntimeException(\sprintf('Failed to download %s file: %s (HTTP %d, errno %d)', $fileType, $error ?: 'Unknown error', $httpCode, $errno));
        }

        // Move temp file to final destination
        if (!rename($tempPath, $destPath)) {
            @unlink($tempPath);
            throw new \RuntimeException(\sprintf('Failed to move downloaded file to: %s', $destPath));
        }

        $this->logger->info('Successfully downloaded GLiNER {type} file', [
            'type' => $fileType,
            'path' => $destPath,
        ]);
    }
}
