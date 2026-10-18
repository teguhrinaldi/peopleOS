<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AccessScope;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Web-admin entry points for leave decisions and HR actions. Every rule (scope, never-own, pending-only,
 * balance, overlap, audit, notifications) is enforced by the leave domain services; this class only adds
 * the HR scope pre-check and turns field-level domain errors into form errors.
 */
class LeaveActions
{
    /** Domain error code => form field that should show it. */
    private const FIELDS = [
        'INVALID_DATE_RANGE' => 'end_date', 'HALF_DAY_SINGLE_DATE' => 'half_day', 'HALF_DAY_NOT_ALLOWED' => 'half_day',
        'LEAVE_SPANS_YEARS' => 'end_date', 'LEAVE_TOO_FAR_IN_PAST' => 'start_date', 'LEAVE_NOTICE_TOO_SHORT' => 'start_date',
        'LEAVE_NO_WORKING_DAYS' => 'start_date', 'LEAVE_TOO_LONG' => 'end_date', 'LEAVE_OVERLAP' => 'start_date',
        'LEAVE_INSUFFICIENT_BALANCE' => 'start_date', 'LEAVE_TYPE_UNAVAILABLE' => 'leave_type_id', 'LEAVE_POLICY_MISSING' => 'leave_type_id',
    ];

    public function __construct(
        private readonly LeaveService $leave,
        private readonly LeaveBalanceService $balances,
        private readonly AccessScope $scope,
    ) {}

    public function decide(User $reviewer, LeaveRequest $request, bool $approve, ?string $note): LeaveRequest
    {
        return $approve ? $this->leave->approve($reviewer, $request, $note) : $this->leave->reject($reviewer, $request, (string) $note);
    }

    public function cancel(User $by, LeaveRequest $request, ?string $reason): LeaveRequest
    {
        return $this->leave->cancel($by, $request, $reason);
    }

    /**
     * HR files leave for an employee (e.g. someone without the app). It is submitted straight away and goes
     * through the normal review queue. One transaction: if submitting fails, no orphan draft is left behind.
     *
     * @param  array{leave_type_id: int, start_date: string, end_date: string, half_day?: ?string, reason?: ?string}  $data
     */
    public function fileOnBehalf(User $hr, Employee $employee, array $data): LeaveRequest
    {
        abort_unless($this->scope->canAccess($hr, $employee, 'leave.manage', null, false), 403);

        return $this->translate(fn () => DB::transaction(fn () => $this->leave->create($employee, $hr, $data, submit: true)));
    }

    public function adjust(User $hr, LeaveBalance $balance, string $delta, string $reason): LeaveBalance
    {
        abort_unless($this->scope->canAccess($hr, $balance->employee_id, 'leave.manage', null, false), 403);

        return $this->translate(fn () => $this->balances->adjust($balance, $delta, $reason, $hr), forceField: 'delta');
    }

    /** @param string|null $forceField show every domain error on this field (used where the form has one field) */
    private function translate(callable $work, ?string $forceField = null): mixed
    {
        try {
            return $work();
        } catch (ApiException $e) {
            $field = $forceField ?? self::FIELDS[$e->errorCode] ?? (array_key_first($e->details) === 'reason' ? 'reason' : null);
            if ($field === null || $e->status >= 500 || in_array($e->status, [401, 403], true)) {
                throw $e;
            }
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }
}
