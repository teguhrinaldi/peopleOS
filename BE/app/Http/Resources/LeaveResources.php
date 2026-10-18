<?php

namespace App\Http\Resources;

use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\Fmt;

/** Array mappers for the leave API (one shape per record, shared by API and web). */
final class LeaveResources
{
    /** @return array<string, mixed> */
    public static function type(LeaveType $t, ?LeavePolicy $policy = null): array
    {
        return [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'description' => $t->description,
            'is_paid' => $t->is_paid,
            'color' => $t->color,
            'is_active' => $t->is_active,
            'policy' => $policy ? self::policy($policy) : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function policy(LeavePolicy $p): array
    {
        return [
            'id' => $p->id,
            'employment_type' => $p->employment_type,
            'annual_allowance' => $p->annual_allowance,
            'unlimited' => $p->isUnlimited(),
            'prorate_first_year' => $p->prorate_first_year,
            'carry_over_max' => $p->carry_over_max,
            'allow_negative' => $p->allow_negative,
            'max_negative_days' => $p->max_negative_days,
            'allow_half_day' => $p->allow_half_day,
            'allow_overlap' => $p->allow_overlap,
            'min_notice_days' => $p->min_notice_days,
            'max_past_days' => $p->max_past_days,
            'max_consecutive_days' => $p->max_consecutive_days,
            'requires_reason' => $p->requires_reason,
        ];
    }

    /** @return array<string, mixed> */
    public static function balance(LeaveBalance $b): array
    {
        return [
            'id' => $b->id,
            'employee_id' => $b->employee_id,
            'leave_type' => $b->relationLoaded('leaveType') ? ['id' => $b->leaveType->id, 'code' => $b->leaveType->code, 'name' => $b->leaveType->name, 'color' => $b->leaveType->color, 'is_paid' => $b->leaveType->is_paid] : ['id' => $b->leave_type_id],
            'year' => $b->year,
            'unlimited' => $b->isUnlimited(),
            'allowance' => $b->allowance,
            'carried_over' => (string) $b->carried_over,
            'adjustment' => (string) $b->adjustment,
            'entitlement' => $b->isUnlimited() ? null : $b->entitlement(),
            'used' => (string) $b->used,
            'reserved' => (string) $b->reserved,
            'available' => $b->available(),
        ];
    }

    /** @return array<string, mixed> */
    public static function request(LeaveRequest $r): array
    {
        return [
            'id' => $r->id,
            'employee' => $r->relationLoaded('employee') ? ['id' => $r->employee->id, 'full_name' => $r->employee->full_name, 'employee_number' => $r->employee->employee_number] : ['id' => $r->employee_id],
            'leave_type' => $r->relationLoaded('leaveType') ? ['id' => $r->leaveType->id, 'code' => $r->leaveType->code, 'name' => $r->leaveType->name, 'color' => $r->leaveType->color, 'is_paid' => $r->leaveType->is_paid] : ['id' => $r->leave_type_id],
            'start_date' => Fmt::date($r->start_date),
            'end_date' => Fmt::date($r->end_date),
            'half_day' => $r->half_day,
            'days' => (string) $r->days,
            'reason' => $r->reason,
            'status' => $r->status->value,
            'submitted_at' => Fmt::iso($r->submitted_at),
            'reviewed_at' => Fmt::iso($r->reviewed_at),
            'reviewer' => $r->relationLoaded('reviewer') && $r->reviewer ? ['id' => $r->reviewer->id, 'name' => $r->reviewer->name] : null,
            'review_note' => $r->review_note,
            'cancelled_at' => Fmt::iso($r->cancelled_at),
            'cancel_reason' => $r->cancel_reason,
            'created_at' => Fmt::iso($r->created_at),
        ];
    }
}
