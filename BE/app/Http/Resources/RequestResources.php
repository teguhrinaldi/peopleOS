<?php

namespace App\Http\Resources;

use App\Models\AttendanceCorrection;
use App\Models\OvertimeRequest;
use App\Support\Fmt;

/** Plain array mappers for the two approval-style records (kept together: they share one shape). */
final class RequestResources
{
    /** @return array<string, mixed> */
    public static function correction(AttendanceCorrection $c): array
    {
        return [
            'id' => $c->id,
            'employee' => $c->relationLoaded('employee') ? ['id' => $c->employee->id, 'full_name' => $c->employee->full_name, 'employee_number' => $c->employee->employee_number] : ['id' => $c->employee_id],
            'attendance_id' => $c->attendance_id,
            'work_date' => Fmt::date($c->work_date),
            'requested_check_in_at' => Fmt::iso($c->requested_check_in_at),
            'requested_check_out_at' => Fmt::iso($c->requested_check_out_at),
            'requested_check_in_time' => Fmt::time($c->requested_check_in_at),
            'requested_check_out_time' => Fmt::time($c->requested_check_out_at),
            'reason' => $c->reason,
            'status' => $c->status->value,
            'reviewed_at' => Fmt::iso($c->reviewed_at),
            'review_note' => $c->review_note,
            'created_at' => Fmt::iso($c->created_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function overtime(OvertimeRequest $o, bool $withPayroll = false): array
    {
        $inclusion = $withPayroll ? $o->inclusion()->first() : null;

        return [
            'id' => $o->id,
            'employee' => $o->relationLoaded('employee') ? ['id' => $o->employee->id, 'full_name' => $o->employee->full_name, 'employee_number' => $o->employee->employee_number] : ['id' => $o->employee_id],
            'work_date' => Fmt::date($o->work_date),
            'start_at' => Fmt::iso($o->start_at),
            'end_at' => Fmt::iso($o->end_at),
            'start_time' => Fmt::time($o->start_at),
            'end_time' => Fmt::time($o->end_at),
            'requested_minutes' => $o->requested_minutes,
            'approved_minutes' => $o->approved_minutes,
            'verified_minutes' => $o->verified_minutes,
            'verification_status' => $o->verification_status,
            'day_type' => $o->day_type,
            'payroll_status' => $o->payroll_status ?? 'NONE',
            // null when not included or when the viewer may not see pay (e.g. a team manager)
            'payroll' => $inclusion ? OvertimeResources::inclusion($inclusion) : null,
            'reason' => $o->reason,
            'status' => $o->status->value,
            'reviewed_at' => Fmt::iso($o->reviewed_at),
            'review_note' => $o->review_note,
            'created_at' => Fmt::iso($o->created_at),
        ];
    }
}
