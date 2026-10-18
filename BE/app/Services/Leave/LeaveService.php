<?php

namespace App\Services\Leave;

use App\Enums\EmployeeStatus;
use App\Enums\LeaveStatus;
use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Notifications\NotificationService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Leave lifecycle:  DRAFT -> PENDING -> APPROVED | REJECTED ;  DRAFT/PENDING/APPROVED(before start) -> CANCELLED
 *
 * Balance maths never trusts a running counter alone: every mutation locks the employee row (serializing
 * that person's requests), then the balance row, and moves days between `reserved` (pending) and `used`
 * (approved). Overlap is refused twice: a readable pre-check and the leave_days_no_overlap unique index,
 * which is what actually wins races between simultaneous requests.
 */
class LeaveService
{
    public function __construct(
        private readonly LeaveCalendar $calendar,
        private readonly LeaveBalanceService $balances,
        private readonly CompanyContext $context,
        private readonly ActivityLogger $activity,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * What a request would consume, without writing anything (powers the app's live preview).
     *
     * @return array{days: string, working_dates: list<string>, balance: ?LeaveBalance, available_after: ?string, policy: LeavePolicy}
     */
    public function preview(Employee $employee, LeaveType $type, string $start, string $end, ?string $halfDay): array
    {
        [$policy, $slots, $days] = $this->evaluate($employee, $type, $start, $end, $halfDay, null);
        $balance = $this->balances->balanceFor($employee, $type, $this->calendar->yearOf($start));
        $available = $balance->available();

        return [
            'days' => $days,
            'working_dates' => array_column($slots, 'date'),
            'balance' => $balance,
            'available_after' => $available === null ? null : bcsub($available, $days, 1),
            'policy' => $policy,
        ];
    }

    /** @param array{leave_type_id: int, start_date: string, end_date: string, half_day?: ?string, reason?: ?string} $data */
    public function create(Employee $employee, User $by, array $data, bool $submit = true): LeaveRequest
    {
        $this->assertCanRequest($employee);
        $type = $this->activeType((int) $data['leave_type_id']);
        // Validate the shape now so a draft is never saved in an impossible state.
        $this->evaluate($employee, $type, $data['start_date'], $data['end_date'], $data['half_day'] ?? null, $data['reason'] ?? null, forDraft: ! $submit);

        $request = DB::transaction(function () use ($employee, $by, $data, $type) {
            $request = LeaveRequest::query()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'half_day' => $data['half_day'] ?? null,
                'reason' => $data['reason'] ?? null,
                'status' => LeaveStatus::Draft,
                'requested_by' => $by->id,
            ]);
            $this->activity->log('leave.draft_created', $request, null, $this->auditView($request), actor: $by);

            return $request;
        });

        return $submit ? $this->submit($by, $request) : $request;
    }

    /** @param array{leave_type_id?: int, start_date?: string, end_date?: string, half_day?: ?string, reason?: ?string} $data */
    public function updateDraft(User $by, LeaveRequest $request, array $data): LeaveRequest
    {
        return DB::transaction(function () use ($by, $request, $data) {
            $locked = $this->lockRow($request);
            Gate::forUser($by)->authorize('update', $locked);
            $this->assertStatus($locked, [LeaveStatus::Draft]);

            $locked->fill(array_intersect_key($data, array_flip(['leave_type_id', 'start_date', 'end_date', 'half_day', 'reason'])));
            $type = $this->activeType((int) $locked->leave_type_id);
            $this->evaluate($locked->employee, $type, $locked->start_date->toDateString(), $locked->end_date->toDateString(), $locked->half_day, $locked->reason, forDraft: true);

            [$old, $new] = $this->activity->diff($locked);
            $locked->save();
            $this->activity->log('leave.draft_updated', $locked, $old, $new, actor: $by);

            return $locked;
        });
    }

    public function submit(User $by, LeaveRequest $request): LeaveRequest
    {
        $result = DB::transaction(function () use ($by, $request) {
            // Lock order everywhere: employee -> request -> balance. Serializes one person's requests.
            $employee = Employee::query()->whereKey($request->employee_id)->lockForUpdate()->firstOrFail();
            $locked = $this->lockRow($request);
            Gate::forUser($by)->authorize('update', $locked);
            $this->assertStatus($locked, [LeaveStatus::Draft]);
            $this->assertCanRequest($employee);

            $type = $this->activeType($locked->leave_type_id);
            $start = $locked->start_date->toDateString();
            $end = $locked->end_date->toDateString();
            [$policy, $slots, $days] = $this->evaluate($employee, $type, $start, $end, $locked->half_day, $locked->reason);

            $this->assertNoOverlap($employee, $slots, $policy, $locked->id);

            $balance = $this->balances->balanceFor($employee, $type, $this->calendar->yearOf($start), lock: true);
            $this->assertBalance($balance, $policy, $days);
            $balance->reserved = bcadd((string) $balance->reserved, $days, 1);
            $balance->save();

            try {
                $this->occupy($locked, $slots, $policy);
            } catch (UniqueConstraintViolationException) {
                throw ApiException::make('LEAVE_OVERLAP', 'These dates overlap another leave request.', 409);
            }

            $locked->fill([
                'status' => LeaveStatus::Pending,
                'days' => $days,
                'leave_balance_id' => $balance->id,
                'policy_snapshot' => $policy->snapshot(),
                'submitted_at' => now(),
            ])->save();

            $this->activity->log('leave.submitted', $locked, ['status' => LeaveStatus::Draft->value], $this->auditView($locked), actor: $by);

            $manager = $employee->currentAssignment?->manager;
            $this->notifications->notify($manager, 'leave.pending_review', 'Leave request to review',
                "{$employee->full_name} requested {$days} day(s) of {$type->name}.", $locked);

            return $locked;
        });

        return $result->refresh();
    }

    public function approve(User $reviewer, LeaveRequest $request, ?string $note = null): LeaveRequest
    {
        $result = DB::transaction(function () use ($reviewer, $request, $note) {
            Employee::query()->whereKey($request->employee_id)->lockForUpdate()->first();
            $locked = $this->lockRow($request);
            Gate::forUser($reviewer)->authorize('approve', $locked);
            $this->assertStatus($locked, [LeaveStatus::Pending]);

            $balance = LeaveBalance::query()->whereKey($locked->leave_balance_id)->lockForUpdate()->firstOrFail();
            $balance->reserved = $this->nonNegative(bcsub((string) $balance->reserved, (string) $locked->days, 1));
            $balance->used = bcadd((string) $balance->used, (string) $locked->days, 1);
            $balance->save();

            $locked->fill(['status' => LeaveStatus::Approved, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $note])->save();

            $this->activity->log('leave.approved', $locked, ['status' => LeaveStatus::Pending->value], ['status' => LeaveStatus::Approved->value, 'review_note' => $note], actor: $reviewer);
            $this->notifications->notify($locked->employee, 'leave.approved', 'Leave approved', $this->summary($locked), $locked);

            return $locked;
        });

        return $result->refresh();
    }

    public function reject(User $reviewer, LeaveRequest $request, string $note): LeaveRequest
    {
        if (trim($note) === '') {
            throw ApiException::make('REJECTION_REASON_REQUIRED', 'A reason is required to reject leave.');
        }

        $result = DB::transaction(function () use ($reviewer, $request, $note) {
            Employee::query()->whereKey($request->employee_id)->lockForUpdate()->first();
            $locked = $this->lockRow($request);
            Gate::forUser($reviewer)->authorize('approve', $locked);
            $this->assertStatus($locked, [LeaveStatus::Pending]);

            $this->release($locked, 'reserved');
            $locked->fill(['status' => LeaveStatus::Rejected, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_note' => $note])->save();

            $this->activity->log('leave.rejected', $locked, ['status' => LeaveStatus::Pending->value], ['status' => LeaveStatus::Rejected->value, 'review_note' => $note], actor: $reviewer);
            $this->notifications->notify($locked->employee, 'leave.rejected', 'Leave rejected', $note, $locked);

            return $locked;
        });

        return $result->refresh();
    }

    /** Owner or an authorised reviewer. Approved leave can only be cancelled before it starts. */
    public function cancel(User $by, LeaveRequest $request, ?string $reason = null, bool $systemAction = false): LeaveRequest
    {
        $result = DB::transaction(function () use ($by, $request, $reason, $systemAction) {
            Employee::query()->whereKey($request->employee_id)->lockForUpdate()->first();
            $locked = $this->lockRow($request);
            if (! $systemAction) {
                Gate::forUser($by)->authorize('cancel', $locked);
            }
            $this->assertStatus($locked, [LeaveStatus::Draft, LeaveStatus::Pending, LeaveStatus::Approved]);

            if ($locked->status === LeaveStatus::Approved && $locked->start_date->toDateString() <= $this->context->today()->toDateString()) {
                throw ApiException::make('LEAVE_ALREADY_STARTED', 'Leave that has already started cannot be cancelled. Ask HR for a correction.', 409);
            }

            $from = $locked->status;
            if ($from === LeaveStatus::Pending) {
                $this->release($locked, 'reserved');
            } elseif ($from === LeaveStatus::Approved) {
                $this->release($locked, 'used');
            }

            $locked->fill(['status' => LeaveStatus::Cancelled, 'cancelled_by' => $by->id, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $this->activity->log('leave.cancelled', $locked, ['status' => $from->value], ['status' => LeaveStatus::Cancelled->value, 'cancel_reason' => $reason], actor: $by);

            return $locked;
        });

        return $result->refresh();
    }

    /** Called when an employee is terminated: pending and future approved leave is released. */
    public function cancelForTermination(Employee $employee, User $by, string $terminationDate): void
    {
        LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where(fn ($q) => $q->whereIn('status', [LeaveStatus::Draft->value, LeaveStatus::Pending->value])
                ->orWhere(fn ($a) => $a->where('status', LeaveStatus::Approved->value)->where('start_date', '>', $terminationDate)))
            ->get()
            ->each(fn (LeaveRequest $r) => $this->cancel($by, $r, 'Employment ended', systemAction: true));
    }

    // ---------------------------------------------------------------- rules

    /**
     * @return array{0: LeavePolicy, 1: list<array{date: string, slots: list<string>}>, 2: string}
     */
    private function evaluate(Employee $employee, LeaveType $type, string $start, string $end, ?string $halfDay, ?string $reason, bool $forDraft = false): array
    {
        $policy = $this->balances->policyFor($employee, $type);
        $tz = $this->context->timezone();
        $startDate = CarbonImmutable::parse($start, $tz)->startOfDay();
        $endDate = CarbonImmutable::parse($end, $tz)->startOfDay();
        $today = $this->context->today();

        if ($endDate->lessThan($startDate)) {
            throw ApiException::make('INVALID_DATE_RANGE', 'The end date must be on or after the start date.');
        }
        if ($halfDay !== null) {
            if (! in_array($halfDay, ['AM', 'PM'], true)) {
                throw ApiException::make('VALIDATION_ERROR', 'half_day must be AM or PM.');
            }
            if (! $startDate->equalTo($endDate)) {
                throw ApiException::make('HALF_DAY_SINGLE_DATE', 'A half-day request must start and end on the same date.');
            }
            if (! $policy->allow_half_day) {
                throw ApiException::make('HALF_DAY_NOT_ALLOWED', "{$type->name} cannot be taken as a half day.");
            }
        }
        if ($this->calendar->yearOf($start) !== $this->calendar->yearOf($end)) {
            throw ApiException::make('LEAVE_SPANS_YEARS', 'Split the request at the start of the new leave year.');
        }
        if ($startDate->lessThan($today->subDays($policy->max_past_days))) {
            throw ApiException::make('LEAVE_TOO_FAR_IN_PAST', $policy->max_past_days === 0
                ? "{$type->name} cannot start in the past."
                : "{$type->name} can start at most {$policy->max_past_days} day(s) in the past.");
        }
        if (! $forDraft && $policy->min_notice_days > 0 && $startDate->lessThan($today->addDays($policy->min_notice_days))) {
            throw ApiException::make('LEAVE_NOTICE_TOO_SHORT', "{$type->name} needs {$policy->min_notice_days} day(s) notice.");
        }
        if (! $forDraft && $policy->requires_reason && trim((string) $reason) === '') {
            throw ApiException::make('VALIDATION_ERROR', 'A reason is required for this leave type.', 422, ['reason' => ['A reason is required for this leave type.']]);
        }

        $slots = $this->calendar->slots($employee, $start, $end, $halfDay);
        if ($slots === [] && ! $forDraft) {
            throw ApiException::make('LEAVE_NO_WORKING_DAYS', 'The selected dates contain no working days.');
        }
        $days = $this->calendar->countDays($slots);

        if ($policy->max_consecutive_days !== null && bccomp($days, (string) $policy->max_consecutive_days, 1) > 0) {
            throw ApiException::make('LEAVE_TOO_LONG', "{$type->name} is limited to {$policy->max_consecutive_days} working day(s) per request.");
        }

        return [$policy, $slots, $days];
    }

    private function assertCanRequest(Employee $employee): void
    {
        if ($employee->status === EmployeeStatus::Terminated) {
            throw ApiException::make('EMPLOYEE_TERMINATED', 'Terminated employees cannot request leave.', 403);
        }
        if ($employee->status !== EmployeeStatus::Active) {
            throw ApiException::make('EMPLOYEE_NOT_ACTIVE', 'Only active employees can request leave.', 403);
        }
    }

    private function assertBalance(LeaveBalance $balance, LeavePolicy $policy, string $days): void
    {
        $available = $balance->available();
        if ($available === null) {
            return; // unlimited
        }

        $floor = $policy->allow_negative ? bcmul((string) $policy->max_negative_days, '-1', 1) : '0.0';
        $after = bcsub($available, $days, 1);

        if (bccomp($after, $floor, 1) < 0) {
            throw ApiException::make('LEAVE_INSUFFICIENT_BALANCE', "Not enough balance: {$available} day(s) available, {$days} requested.", 422, [
                'available' => $available, 'requested' => $days,
            ]);
        }
    }

    /** @param list<array{date: string, slots: list<string>}> $slots */
    private function assertNoOverlap(Employee $employee, array $slots, LeavePolicy $policy, int $ignoreRequestId): void
    {
        if ($policy->allow_overlap || $slots === []) {
            return;
        }

        $clash = LeaveRequestDay::query()
            ->where('employee_id', $employee->id)
            ->where('blocking', true)
            ->where('leave_request_id', '!=', $ignoreRequestId)
            ->whereIn('date', array_column($slots, 'date'))
            ->with('request.leaveType')
            ->get()
            ->first(fn (LeaveRequestDay $d) => in_array($d->slot, $this->slotsFor($slots, $d->date->toDateString()), true));

        if ($clash) {
            throw ApiException::make('LEAVE_OVERLAP', 'These dates overlap your '.strtolower($clash->request->leaveType->name).' request ('.$clash->request->status->value.').', 409, [
                'conflicting_request_id' => $clash->leave_request_id,
            ]);
        }
    }

    /** @param list<array{date: string, slots: list<string>}> $slots */
    private function occupy(LeaveRequest $request, array $slots, LeavePolicy $policy): void
    {
        $rows = [];
        foreach ($slots as $day) {
            foreach ($day['slots'] as $slot) {
                $rows[] = [
                    'leave_request_id' => $request->id, 'employee_id' => $request->employee_id,
                    'date' => $day['date'], 'slot' => $slot, 'blocking' => $policy->allow_overlap ? null : true,
                ];
            }
        }

        LeaveRequestDay::query()->where('leave_request_id', $request->id)->delete();
        LeaveRequestDay::query()->insert($rows);
    }

    /** Give the days back to the balance and free the calendar slots. */
    private function release(LeaveRequest $request, string $bucket): void
    {
        $balance = LeaveBalance::query()->whereKey($request->leave_balance_id)->lockForUpdate()->first();
        if ($balance) {
            $balance->{$bucket} = $this->nonNegative(bcsub((string) $balance->{$bucket}, (string) $request->days, 1));
            $balance->save();
        }

        LeaveRequestDay::query()->where('leave_request_id', $request->id)->update(['blocking' => null]);
    }

    private function lockRow(LeaveRequest $request): LeaveRequest
    {
        return LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Runs after authorization, so outsiders never learn a request's state from the error.
     *
     * @param  list<LeaveStatus>  $allowed
     */
    private function assertStatus(LeaveRequest $locked, array $allowed): void
    {
        if (! in_array($locked->status, $allowed, true)) {
            throw ApiException::make('REQUEST_NOT_PENDING', "This leave request is {$locked->status->value} and cannot be changed that way.", 409, [
                'status' => $locked->status->value,
            ]);
        }
    }

    private function activeType(int $id): LeaveType
    {
        $type = LeaveType::query()->where('company_id', $this->context->id())->find($id);

        if (! $type || ! $type->is_active) {
            throw ApiException::make('LEAVE_TYPE_UNAVAILABLE', 'This leave type is not available.', 422);
        }

        return $type;
    }

    /** @param list<array{date: string, slots: list<string>}> $slots @return list<string> */
    private function slotsFor(array $slots, string $date): array
    {
        foreach ($slots as $day) {
            if ($day['date'] === $date) {
                return $day['slots'];
            }
        }

        return [];
    }

    private function nonNegative(string $value): string
    {
        return bccomp($value, '0', 1) < 0 ? '0.0' : $value;
    }

    private function summary(LeaveRequest $r): string
    {
        $range = $r->start_date->equalTo($r->end_date)
            ? $r->start_date->format('D, d M Y')
            : $r->start_date->format('d M').' – '.$r->end_date->format('d M Y');

        return "{$r->leaveType->name}, {$range} ({$r->days} day(s)).";
    }

    /** @return array<string, mixed> */
    private function auditView(LeaveRequest $r): array
    {
        return [
            'leave_type_id' => $r->leave_type_id, 'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'half_day' => $r->half_day, 'days' => (string) $r->days, 'status' => $r->status->value,
        ];
    }
}
