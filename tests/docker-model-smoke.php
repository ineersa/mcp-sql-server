<?php

declare(strict_types=1);

$model = new GlinerWrapper('/app/models/tokenizer.json', '/app/models/model.onnx');
$predictions = $model->predictBatch(['Contact john@example.com for details.'], ['email']);
if ([] === ($predictions[0] ?? [])) {
    throw new RuntimeException('Bundled model did not detect the test email.');
}
foreach (['LICENSE.html', 'Notice.txt'] as $file) {
    if (!is_file('/app/models/'.$file)) {
        throw new RuntimeException('Missing model license file: '.$file);
    }
}
fwrite(\STDOUT, "Bundled model inference passed without network access.\n");
