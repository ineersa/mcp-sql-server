<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

$tokenizerPath = __DIR__.'/models/tokenizer.json';
$modelPath = __DIR__.'/models/model.onnx';

if (!file_exists($tokenizerPath) || !file_exists($modelPath)) {
    echo "Model files not found.\n";
    exit(1);
}

$gliner = new GlinerWrapper($tokenizerPath, $modelPath);

$text = <<<EOT
     | ID | Title | Created At | Updated At | Published At | Processed At |
     |----|-------|------------|------------|--------------|--------------|
     | 1 | How to install/upgrade PHP on a server | REDACTED 10:38:10 | REDACTED | REDACTED | REDACTED 15:32:27 |
     | 2 | Running Golang app as a service | REDACTED | 2025-06-26 15:18:52 | REDACTED | REDACTED 15:32:27 |
     | 3 | How to run SSH commands on GitHub Actions | REDACTED 15:25:33 | REDACTED 15:34:44 | REDACTED 15:34:44 | REDACTED 15:32:27 |
     | 4 | Fixing flash of unstyled content | REDACTED | REDACTED 11:23:12 | REDACTED | REDACTED 15:32:27 |
     | 5 | Enabling gzip encoding in NGINX | REDACTED | REDACTED 11:54:23 | REDACTED | REDACTED 15:32:27 |
     | 6 | Searching for big files/directories on a server | REDACTED 12:11:28 | REDACTED 12:11:50 | REDACTED | REDACTED 15:32:27 |
     | 7 | Using View Transitions with HTMX | 2024-06-17 13:00:50 | 2024-06-17 13:01:41 | 2024-06-17 13:01:07 | 2025-07-02 15:32:27 |
     | 8 | Adding Proxy to serve images as webp | REDACTED 12:24:34 | REDACTED 13:00:46 | REDACTED 12:46:01 | REDACTED 15:32:27 |
     | 9 | Simple way to check site analytics | REDACTED 13:24:57 | REDACTED 13:33:29 | REDACTED | REDACTED 15:32:27 |
     | 10 | Using ollama to host code companion | REDACTED | REDACTED 08:27:08 | REDACTED | 2025-07-02 15:32:27 |
EOT;

$currentLabels = ['email', 'city'];
$expandedLabels = ['email', 'city', 'date', 'time', 'timestamp'];

echo "--- Text ---\n$text\n\n";

echo '--- Predictions (Current Labels: '.implode(', ', $currentLabels).") ---\n";
$predictionsBatch = $gliner->predictBatch([$text], $currentLabels);
$predictions = $predictionsBatch[0];
foreach ($predictions as $p) {
    if ($p['score'] > 0.4) { // Assuming generic threshold
        echo sprintf("[%s] '%s' (%.2f)\n", $p['label'], substr($text, $p['start'], $p['end'] - $p['start']), $p['score']);
    }
}

echo "\n--- Predictions (Expanded Labels: ".implode(', ', $expandedLabels).") ---\n";
$predictionsBatch = $gliner->predictBatch([$text], $expandedLabels);
$predictions = $predictionsBatch[0];
foreach ($predictions as $p) {
    if ($p['score'] > 0.4) {
        echo sprintf("[%s] '%s' (%.2f)\n", $p['label'], substr($text, $p['start'], $p['end'] - $p['start']), $p['score']);
    }
}
