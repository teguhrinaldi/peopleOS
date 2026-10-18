<?php

namespace App\Services\Overtime;

use App\Exceptions\ApiException;
use App\Models\OvertimePayrollInclusion;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY way to undo a finalized overtime inclusion: void it with a reason. The finalized payslip is never
 * edited; the money correction (if any) is recorded as a manual entry in an adjustment period. The request
 * becomes ADJUSTED and is never picked up automatically again.
 */
class OvertimePayrollService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function void(User $actor, OvertimeRequest $request, string $reason): OvertimePayrollInclusion
    {
        if (! $actor->hasPermission('payroll.manage')) {
            throw ApiException::make('FORBIDDEN', 'You do not have permission to perform this action.', 403, ['permission' => 'payroll.manage']);
        }
        if (trim($reason) === '') {
            throw ApiException::make('REASON_REQUIRED', 'A reason is required to void a payroll inclusion.');
        }

        return DB::transaction(function () use ($actor, $request, $reason) {
            $locked = OvertimeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $inclusion = OvertimePayrollInclusion::query()->where('active_request_id', $locked->id)->lockForUpdate()->first();
            if (! $inclusion) {
                throw ApiException::make('NOT_INCLUDED', 'This overtime request is not included in any payroll.', 409);
            }
            if ($inclusion->locked_at === null) {
                throw ApiException::make('PAYROLL_NOT_FINALIZED', 'The payroll is still open: regenerate it instead of voiding.', 409);
            }

            $inclusion->fill(['active_request_id' => null, 'voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason])->save();
            $locked->fill(['payroll_status' => OvertimeRequest::PAYROLL_ADJUSTED])->save();

            $this->activity->log('overtime.payroll_voided', $locked, ['payroll_status' => OvertimeRequest::PAYROLL_INCLUDED], [
                'payroll_status' => OvertimeRequest::PAYROLL_ADJUSTED, 'inclusion_id' => $inclusion->id,
                'payroll_run_id' => $inclusion->payroll_run_id, 'amount' => (string) $inclusion->amount, 'reason' => $reason,
            ], null, $actor);

            return $inclusion;
        });
    }
}
