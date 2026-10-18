<?php

namespace App\Services\Leave;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\LeaveRequestDay;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only answers about approved leave, per calendar day. This is the contract other modules
 * (payroll, attendance reports, today) use; they never interpret leave_requests themselves.
 * Every half-day slot counts 0.5.
 */
class LeaveLedger
{
    /** Approved unpaid leave days in [from, to] (inclusive, Y-m-d), as a bcmath string with one decimal. */
    public function unpaidDaysBetween(Employee $employee, string $from, string $to): string
    {
        return $this->days($employee, $from, $to, fn ($t) => $t->where('is_paid', false));
    }

    public function paidDaysBetween(Employee $employee, string $from, string $to): string
    {
        return $this->days($employee, $from, $to, fn ($t) => $t->where('is_paid', true));
    }

    /** 'FULL', 'AM', 'PM' or null: the approved leave the employee is on for that date. */
    public function coverageOn(Employee $employee, string $date): ?string
    {
        $slots = $this->approvedDays($employee)->whereDate('leave_request_days.date', $date)->pluck('slot')->unique()->values()->all();

        return match (count($slots)) {
            0 => null,
            1 => $slots[0],
            default => 'FULL',
        };
    }

    /** @return array<string, string> Y-m-d => 'FULL'|'AM'|'PM' for employees in range; used by reports (one query). */
    public function coverageMap(array $employeeIds, string $from, string $to): array
    {
        $rows = LeaveRequestDay::query()
            ->join('leave_requests', 'leave_requests.id', '=', 'leave_request_days.leave_request_id')
            ->where('leave_requests.status', LeaveStatus::Approved->value)
            ->whereIn('leave_request_days.employee_id', $employeeIds)
            ->whereBetween('leave_request_days.date', [$from, $to])
            ->get(['leave_request_days.employee_id', 'leave_request_days.date', 'leave_request_days.slot']);

        $map = [];
        foreach ($rows as $row) {
            $key = $row->employee_id.'|'.$row->date->toDateString();
            $map[$key] = isset($map[$key]) && $map[$key] !== $row->slot ? 'FULL' : $row->slot;
        }

        return $map;
    }

    private function days(Employee $employee, string $from, string $to, callable $typeFilter): string
    {
        $slots = $this->approvedDays($employee)
            ->whereBetween('leave_request_days.date', [$from, $to])
            ->whereIn('leave_requests.leave_type_id', fn ($q) => $typeFilter($q->select('id')->from('leave_types')))
            ->count();

        return bcdiv((string) $slots, '2', 1);
    }

    private function approvedDays(Employee $employee): Builder
    {
        return LeaveRequestDay::query()
            ->join('leave_requests', 'leave_requests.id', '=', 'leave_request_days.leave_request_id')
            ->where('leave_requests.status', LeaveStatus::Approved->value)
            ->where('leave_request_days.employee_id', $employee->id);
    }
}
