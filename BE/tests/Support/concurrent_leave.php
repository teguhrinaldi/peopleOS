<?php

/**
 * Child process for LeaveConcurrencyTest: waits for a shared start time, then submits or approves
 * one leave request through the real service and prints the outcome as JSON.
 *
 * usage: php concurrent_leave.php <submit|approve> <leaveRequestId> <actingUserId> <startAtMicrotime>
 */

use App\Exceptions\ApiException;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\LeaveService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $action, $requestId, $userId, $startAt] = $argv;

$request = LeaveRequest::query()->findOrFail((int) $requestId);
$user = User::query()->findOrFail((int) $userId);
$service = app(LeaveService::class);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $result = $action === 'submit' ? $service->submit($user, $request) : $service->approve($user, $request);
    echo json_encode(['ok' => true, 'status' => $result->status->value]);
} catch (ApiException $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'CRASH', 'message' => $e->getMessage()]);
}
