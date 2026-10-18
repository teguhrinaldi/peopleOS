<?php

/**
 * Child process for ReimbursementConcurrencyTest: waits for a shared start time, then runs one action through the real
 * services and prints the outcome as JSON.
 *
 * usage: php concurrent_reimbursement.php pay     <requestId>  <actingUserId> <startAt> <idempotencyKey>
 *        php concurrent_reimbursement.php approve <requestId>  <actingUserId> <startAt>
 *        php concurrent_reimbursement.php create  <employeeId> <actingUserId> <startAt> <categoryId>
 */

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\ExpenseRequest;
use App\Models\User;
use App\Services\Reimbursements\ReimbursementPaymentService;
use App\Services\Reimbursements\ReimbursementService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $action, $targetId, $userId, $startAt] = $argv;
$extra = $argv[5] ?? null;

$user = User::query()->findOrFail((int) $userId);
$service = app(ReimbursementService::class);
$request = $action === 'create' ? null : ExpenseRequest::query()->findOrFail((int) $targetId);
$employee = $action === 'create' ? Employee::query()->findOrFail((int) $targetId) : null;

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $out = match ($action) {
        'pay' => (function () use ($user, $request, $extra) {
            [$claim, $payment, $replayed] = app(ReimbursementPaymentService::class)->pay($user, $request, (string) $extra, ['method' => 'BANK_TRANSFER', 'reference' => 'TRX']);

            return ['status' => $claim->status->value, 'payment_id' => $payment->id, 'replayed' => $replayed];
        })(),
        'approve' => ['status' => $service->approve($user, $request)->status->value],
        'create' => (function () use ($user, $service, $employee, $extra) {
            $claim = $service->create($user, $employee, [
                'expense_category_id' => (int) $extra, 'expense_date' => now()->setTimezone('Asia/Jakarta')->subDay()->toDateString(),
                'submitted_amount' => '1000', 'business_purpose' => 'Concurrency',
            ]);

            return ['status' => $claim->status->value, 'number' => $claim->request_number];
        })(),
    };
    echo json_encode(['ok' => true] + $out);
} catch (ApiException $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'CRASH', 'message' => $e->getMessage()]);
}
