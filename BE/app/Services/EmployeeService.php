<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Exceptions\ApiException;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Leave\LeaveService;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Employee lifecycle: PENDING -> ACTIVE <-> INACTIVE -> TERMINATED (terminal).
 * Placement (department, position, manager, location, shift) is stored as dated assignments so history survives.
 */
class EmployeeService
{
    private const TRANSITIONS = [
        'PENDING' => ['ACTIVE', 'TERMINATED'],
        'ACTIVE' => ['INACTIVE', 'TERMINATED'],
        'INACTIVE' => ['ACTIVE', 'TERMINATED'],
        'TERMINATED' => [],
    ];

    private const ASSIGNMENT_FIELDS = [
        'branch_id', 'department_id', 'position_id', 'manager_id', 'work_location_id', 'shift_id',
    ];

    private const PROFILE_FIELDS = [
        'employee_number', 'full_name', 'gender', 'date_of_birth', 'phone', 'email', 'address', 'join_date',
        'employment_type', 'photo_path',
    ];

    public function __construct(
        private readonly CompanyContext $context,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $data  profile + assignment fields (+ optional login_* keys)
     */
    public function create(array $data, ?User $actor = null): Employee
    {
        return DB::transaction(function () use ($data, $actor) {
            $status = $data['status'] ?? EmployeeStatus::Pending->value;
            $user = null;
            if (! empty($data['create_login'])) {
                $user = User::query()->create([
                    'name' => $data['full_name'],
                    'email' => $data['login_email'] ?? $data['email'],
                    'password' => $data['password'],
                    // A login for someone who has not started yet stays disabled until activation.
                    'is_active' => $status === EmployeeStatus::Active->value,
                ]);
                $user->assignRole($data['role_slug'] ?? 'employee');
            }

            $employee = Employee::query()->create(array_merge(
                array_intersect_key($data, array_flip(self::PROFILE_FIELDS)),
                [
                    'company_id' => $this->context->id(),
                    'user_id' => $user?->id,
                    'status' => $status,
                ],
            ));

            $this->openAssignment($employee, $data, $data['effective_from'] ?? $employee->join_date->toDateString(), $actor);

            $this->activity->log('employee.created', $employee, null, $this->auditView($employee), actor: $actor);

            return $employee->load('currentAssignment');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Employee $employee, array $data, ?User $actor = null): Employee
    {
        if ($employee->isTerminated()) {
            throw ApiException::make('EMPLOYEE_TERMINATED', 'Terminated employees are read-only.', 409);
        }

        return DB::transaction(function () use ($employee, $data, $actor) {
            $employee->fill(array_intersect_key($data, array_flip(self::PROFILE_FIELDS)));

            if ($employee->isDirty()) {
                [$old, $new] = $this->activity->diff($employee);
                $previousEmail = $employee->getOriginal('email');
                $employee->save();
                $this->activity->log('employee.updated', $employee, $old, $new, actor: $actor);

                if ($user = $employee->user) {
                    $user->name = $employee->full_name;
                    // Keep the login email in step only when it was the same address (never hijack a separate login email).
                    if ($employee->email && $user->email === $previousEmail) {
                        $user->email = $employee->email;
                    }
                    $user->save();
                }
            }

            return $employee;
        });
    }

    /**
     * Start a new dated assignment. The open one is closed the day before, so history stays intact.
     *
     * @param  array<string, mixed>  $data
     */
    public function reassign(Employee $employee, array $data, string $effectiveFrom, ?User $actor = null): EmployeeAssignment
    {
        if ($employee->isTerminated()) {
            throw ApiException::make('EMPLOYEE_TERMINATED', 'Terminated employees cannot be reassigned.', 409);
        }

        return DB::transaction(function () use ($employee, $data, $effectiveFrom, $actor) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $latest = EmployeeAssignment::query()
                ->where('employee_id', $employee->id)
                ->orderByDesc('effective_from')->orderByDesc('id')
                ->first();

            if ($latest && $effectiveFrom <= $latest->effective_from->toDateString()) {
                throw ApiException::make(
                    'ASSIGNMENT_OVERLAP',
                    'The new assignment must start after the previous one ('.$latest->effective_from->toDateString().').',
                    422,
                );
            }

            $previous = $latest?->only(self::ASSIGNMENT_FIELDS);
            $assignment = $this->openAssignment($employee, $data, $effectiveFrom, $actor);

            $this->activity->log('employee.reassigned', $employee, $previous, $assignment->only(self::ASSIGNMENT_FIELDS) + ['effective_from' => $effectiveFrom], actor: $actor);

            return $assignment;
        });
    }

    public function changeStatus(Employee $employee, EmployeeStatus $to, ?User $actor = null): Employee
    {
        if ($to === EmployeeStatus::Terminated) {
            throw ApiException::make('USE_TERMINATE', 'Use terminate() to end employment.', 422);
        }

        $from = $employee->status;
        if (! in_array($to->value, self::TRANSITIONS[$from->value], true)) {
            throw ApiException::make('INVALID_STATUS_TRANSITION', "Cannot change an employee from {$from->value} to {$to->value}.", 409);
        }

        return DB::transaction(function () use ($employee, $to, $from, $actor) {
            $employee->status = $to;
            $employee->save();
            $employee->user?->forceFill(['is_active' => $to === EmployeeStatus::Active])->save();

            $this->activity->log('employee.status_changed', $employee, ['status' => $from->value], ['status' => $to->value], actor: $actor);

            return $employee;
        });
    }

    public function terminate(Employee $employee, string $date, string $reason, ?User $actor = null): Employee
    {
        if ($employee->isTerminated()) {
            throw ApiException::make('EMPLOYEE_TERMINATED', 'This employee is already terminated.', 409);
        }

        return DB::transaction(function () use ($employee, $date, $reason, $actor) {
            $from = $employee->status;

            $employee->fill([
                'status' => EmployeeStatus::Terminated,
                'terminated_at' => $date,
                'termination_reason' => $reason,
            ])->save();

            EmployeeAssignment::query()
                ->where('employee_id', $employee->id)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
                ->update(['effective_to' => $date]);

            if ($employee->user) {
                $employee->user->forceFill(['is_active' => false])->save();
                $employee->user->tokens()->delete(); // signs the mobile app out immediately
            }

            // Pending and not-yet-started leave is released back; history stays.
            if ($actor) {
                app(LeaveService::class)->cancelForTermination($employee, $actor, $date);
            }

            $this->activity->log('employee.terminated', $employee, ['status' => $from->value], [
                'status' => EmployeeStatus::Terminated->value,
                'terminated_at' => $date,
                'termination_reason' => $reason,
            ], actor: $actor);

            return $employee;
        });
    }

    /**
     * Soft delete. Only a never-used PENDING record may go; everything else is kept for history.
     */
    public function delete(Employee $employee, ?User $actor = null): void
    {
        $hasHistory = Attendance::query()->where('employee_id', $employee->id)->exists()
            || AttendanceCorrection::query()->where('employee_id', $employee->id)->exists()
            || OvertimeRequest::query()->where('employee_id', $employee->id)->exists();

        if ($employee->status !== EmployeeStatus::Pending || $hasHistory) {
            throw ApiException::make(
                'EMPLOYEE_DELETE_RESTRICTED',
                'Only pending employees with no history can be deleted. Terminate the employee instead.',
                409,
            );
        }

        DB::transaction(function () use ($employee, $actor) {
            $this->activity->log('employee.deleted', $employee, $this->auditView($employee), null, actor: $actor);
            $employee->user?->forceFill(['is_active' => false])->save();
            $employee->delete();
        });
    }

    /** @param array<string, mixed> $data */
    private function openAssignment(Employee $employee, array $data, string $effectiveFrom, ?User $actor): EmployeeAssignment
    {
        $this->assertManagerIsValid($employee, $data['manager_id'] ?? null);

        $from = CarbonImmutable::parse($effectiveFrom)->toDateString();

        EmployeeAssignment::query()
            ->where('employee_id', $employee->id)
            ->whereNull('effective_to')
            ->update(['effective_to' => CarbonImmutable::parse($from)->subDay()->toDateString()]);

        return EmployeeAssignment::query()->create(array_merge(
            array_intersect_key($data, array_flip(self::ASSIGNMENT_FIELDS)),
            [
                'employee_id' => $employee->id,
                'effective_from' => $from,
                'reason' => $data['assignment_reason'] ?? null,
                'created_by' => $actor?->id,
            ],
        ));
    }

    /** A manager must be a different employee and must not report (directly or not) to this employee. */
    private function assertManagerIsValid(Employee $employee, mixed $managerId): void
    {
        if ($managerId === null || $managerId === '') {
            return;
        }

        if ((int) $managerId === $employee->id) {
            throw ApiException::make('INVALID_MANAGER', 'An employee cannot be their own manager.');
        }

        $seen = [];
        $cursor = (int) $managerId;
        while ($cursor && ! isset($seen[$cursor])) {
            $seen[$cursor] = true;
            if ($cursor === $employee->id) {
                throw ApiException::make('INVALID_MANAGER', 'That manager already reports to this employee.');
            }
            $cursor = (int) EmployeeAssignment::query()
                ->where('employee_id', $cursor)
                ->orderByDesc('effective_from')->orderByDesc('id')
                ->value('manager_id');
        }
    }

    /** @return array<string, mixed> */
    private function auditView(Employee $employee): array
    {
        return $employee->only(['employee_number', 'full_name', 'email', 'status', 'employment_type', 'join_date']);
    }
}
