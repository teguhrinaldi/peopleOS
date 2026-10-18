<?php

namespace App\Services\Attendance;

use App\Enums\EmployeeStatus;
use App\Enums\RequestStatus;
use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Notifications\NotificationService;
use App\Services\Overtime\OvertimeEligibility;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Overtime request foundation: request -> manager/HR review -> recorded result.
 * Approval freezes the day type and records a first attendance verification; the request then waits for payroll
 * (payroll_status PENDING_PAYROLL). Pay is computed by the overtime rule engine during payroll generation
 * (App\Services\Overtime\OvertimeEarningSource, docs/overtime.md), which re-verifies against attendance.
 */
class OvertimeService
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly ShiftCalculator $calculator,
        private readonly ActivityLogger $activity,
        private readonly NotificationService $notifications,
        private readonly OvertimeEligibility $eligibility,
    ) {}

    public function request(
        Employee $employee,
        User $requestedBy,
        string $workDate,
        CarbonImmutable $startAt,
        CarbonImmutable $endAt,
        string $reason,
    ): OvertimeRequest {
        if ($employee->status !== EmployeeStatus::Active) {
            throw ApiException::make('EMPLOYEE_NOT_ACTIVE', 'Only active employees can request overtime.', 403);
        }

        $minutes = $this->calculator->minutesBetween($startAt, $endAt);
        if ($minutes <= 0) {
            throw ApiException::make('INVALID_EVENT_TIME', 'Overtime must end after it starts.');
        }

        $max = (int) config('peopleos.attendance.overtime_max_minutes', 720);
        if ($minutes > $max) {
            throw ApiException::make('OVERTIME_TOO_LONG', 'A single overtime request cannot exceed '.intdiv($max, 60).' hours.');
        }

        $date = CarbonImmutable::parse($workDate, $this->context->timezone())->startOfDay();
        $today = $this->context->today();
        if ($date->lessThan($today->subDays(30)) || $date->greaterThan($today->addDays(30))) {
            throw ApiException::make('INVALID_WORK_DATE', 'Overtime must be within 30 days of today.');
        }

        return DB::transaction(function () use ($employee, $requestedBy, $date, $startAt, $endAt, $minutes, $reason) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $overlap = OvertimeRequest::query()
                ->where('employee_id', $employee->id)
                ->whereIn('status', [RequestStatus::Pending->value, RequestStatus::Approved->value])
                ->where('start_at', '<', $endAt)
                ->where('end_at', '>', $startAt)
                ->exists();
            if ($overlap) {
                throw ApiException::make('OVERTIME_OVERLAP', 'This overlaps another overtime request.', 409);
            }

            $request = OvertimeRequest::query()->create([
                'employee_id' => $employee->id,
                'work_date' => $date->toDateString(),
                'start_at' => $startAt,
                'end_at' => $endAt,
                'requested_minutes' => $minutes,
                'reason' => $reason,
                'status' => RequestStatus::Pending,
                'requested_by' => $requestedBy->id,
            ]);

            $this->activity->log('overtime.requested', $request, null, [
                'work_date' => $date->toDateString(),
                'requested_minutes' => $minutes,
            ], actor: $requestedBy);

            return $request;
        });
    }

    public function approve(User $reviewer, OvertimeRequest $request, ?int $approvedMinutes = null, ?string $note = null): OvertimeRequest
    {
        return DB::transaction(function () use ($reviewer, $request, $approvedMinutes, $note) {
            $locked = $this->lockPending($request);
            Gate::forUser($reviewer)->authorize('approve', $locked);

            $approved = $approvedMinutes ?? $locked->requested_minutes;
            if ($approved <= 0 || $approved > $locked->requested_minutes) {
                throw ApiException::make('VALIDATION_ERROR', 'Approved minutes must be between 1 and the requested minutes.');
            }

            $locked->fill([
                'status' => RequestStatus::Approved,
                'approved_minutes' => $approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
                'payroll_status' => OvertimeRequest::PAYROLL_PENDING,
            ]);
            $check = $this->eligibility->evaluate($locked);
            $locked->fill([
                'day_type' => $check['day_type'],
                'verified_minutes' => $check['verified'],
                'verification_status' => $check['status'],
                'verified_at' => now(),
            ])->save();

            $this->activity->log('overtime.approved', $locked, null, ['approved_minutes' => $approved, 'review_note' => $note], actor: $reviewer);
            $this->notifications->notify($locked->employee, 'overtime.approved', 'Overtime approved',
                "{$approved} minutes approved for ".$locked->work_date->format('D, d M').'.', $locked);

            return $locked->refresh();
        });
    }

    public function reject(User $reviewer, OvertimeRequest $request, string $note): OvertimeRequest
    {
        if (trim($note) === '') {
            throw ApiException::make('REJECTION_REASON_REQUIRED', 'A reason is required to reject overtime.');
        }

        return DB::transaction(function () use ($reviewer, $request, $note) {
            $locked = $this->lockPending($request);
            Gate::forUser($reviewer)->authorize('approve', $locked);

            $locked->fill([
                'status' => RequestStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->activity->log('overtime.rejected', $locked, null, ['review_note' => $note], actor: $reviewer);
            $this->notifications->notify($locked->employee, 'overtime.rejected', 'Overtime rejected', $note, $locked);

            return $locked->refresh();
        });
    }

    public function cancel(User $user, OvertimeRequest $request): OvertimeRequest
    {
        return DB::transaction(function () use ($user, $request) {
            $locked = $this->lockPending($request);
            Gate::forUser($user)->authorize('cancel', $locked);

            $locked->fill(['status' => RequestStatus::Cancelled])->save();
            $this->activity->log('overtime.cancelled', $locked, null, null, actor: $user);

            return $locked->refresh();
        });
    }

    private function lockPending(OvertimeRequest $request): OvertimeRequest
    {
        $locked = OvertimeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== RequestStatus::Pending) {
            throw ApiException::make('REQUEST_NOT_PENDING', 'This request has already been decided.', 409);
        }

        return $locked;
    }
}
