<?php

/**
 * Child process for DocumentConcurrencyTest: waits for a shared start time, then runs the real
 * DocumentService::refreshStatuses() (what `documents:refresh-status` does) and prints the result.
 *
 * usage: php concurrent_documents.php <startAtMicrotime>
 */

use App\Services\Documents\DocumentService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $startAt] = $argv;
$service = app(DocumentService::class);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    echo json_encode(['ok' => true] + $service->refreshStatuses());
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
