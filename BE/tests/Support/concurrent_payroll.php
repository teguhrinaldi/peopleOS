<?php

/**
 * Child process for PayrollConcurrencyTest: waits for a shared start time, then runs ONE payroll action
 * through the real services and prints the outcome as JSON. Real parallel MySQL connections, no mocks.
 *
 * usage: php concurrent_payroll.php <generate|approve|finalize> <periodId> <userId> <startAtMicrotime>
 */

use App\Exceptions\ApiException;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\Payroll\PayrollGenerator;
use App\Services\Payroll\PayrollWorkflow;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $action, $periodId, $userId, $startAt] = $argv;

$period = PayrollPeriod::query()->findOrFail((int) $periodId);
$user = User::query()->findOrFail((int) $userId);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $run = match ($action) {
        'generate' => app(PayrollGenerator::class)->generate($user, $period),
        'approve' => app(PayrollWorkflow::class)->approve($user, $period),
        'finalize' => app(PayrollWorkflow::class)->finalize($user, $period),
    };
    echo json_encode(['ok' => true, 'status' => $run->status->value]);
} catch (ApiException $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'CRASH', 'message' => $e->getMessage()]);
}
