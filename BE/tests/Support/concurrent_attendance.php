<?php

/**
 * Child process for ConcurrencyTest: waits for a shared start time, then performs one check-in/out
 * through the real service and prints the outcome as JSON. Real parallel MySQL connections, no mocks.
 *
 * usage: php concurrent_attendance.php <employeeId> <idempotencyKey> <in|out> <startAtMicrotime>
 */

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Services\Attendance\AttendanceCapture;
use App\Services\Attendance\AttendanceService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $employeeId, $key, $type, $startAt] = $argv;

$employee = Employee::query()->findOrFail((int) $employeeId);
$service = app(AttendanceService::class);
$capture = new AttendanceCapture($key, -6.9219, 107.6072, 10.0);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $result = $type === 'in' ? $service->checkIn($employee, $capture) : $service->checkOut($employee, $capture);
    echo json_encode(['ok' => true, 'replayed' => $result->replayed, 'event' => $result->event->id]);
} catch (ApiException $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'CRASH', 'message' => $e->getMessage()]);
}
