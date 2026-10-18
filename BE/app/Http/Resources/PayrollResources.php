<?php

namespace App\Http\Resources;

use App\Models\EmployeeSalaryComponent;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollItemLine;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Services\Payroll\PayrollStateMachine;
use App\Support\Fmt;

/**
 * Plain array mappers for the payroll API. Money is returned as decimal STRINGS ("8000000.00"); clients format
 * them with `currency` + `decimals`. Storage paths, processing tokens and file hashes are never exposed.
 */
final class PayrollResources
{
    /** @return array<string, mixed> */
    public static function component(SalaryComponent $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'kind' => $c->kind->value,
            'calculation' => $c->calculation->value,
            'is_basic' => $c->is_basic,
            'default_amount' => $c->default_amount,
            'default_rate' => $c->default_rate,
            'description' => $c->description,
            'sort_order' => $c->sort_order,
            'is_active' => $c->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function structure(SalaryStructure $s): array
    {
        return [
            'id' => $s->id,
            'code' => $s->code,
            'name' => $s->name,
            'description' => $s->description,
            'is_active' => $s->is_active,
            'components' => $s->relationLoaded('lines') ? $s->lines->map(fn ($l) => [
                'salary_component_id' => $l->salary_component_id,
                'code' => $l->component?->code,
                'name' => $l->component?->name,
                'kind' => $l->component?->kind->value,
                'calculation' => $l->component?->calculation->value,
                'amount' => $l->amount,
                'rate' => $l->rate,
                'sort_order' => $l->sort_order,
            ])->values()->all() : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function salaryValue(EmployeeSalaryComponent $r): array
    {
        return [
            'id' => $r->id,
            'salary_component_id' => $r->salary_component_id,
            'code' => $r->component?->code,
            'name' => $r->component?->name,
            'kind' => $r->component?->kind->value,
            'calculation' => $r->component?->calculation->value,
            'amount' => $r->amount,
            'rate' => $r->rate,
            'effective_from' => Fmt::date($r->effective_from),
            'effective_to' => Fmt::date($r->effective_to),
            'salary_structure_id' => $r->salary_structure_id,
            'reason' => $r->reason,
            'created_by' => $r->relationLoaded('creator') ? $r->creator?->name : null,
            'created_at' => Fmt::iso($r->created_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function period(PayrollPeriod $p): array
    {
        return [
            'id' => $p->id,
            'type' => $p->type->value,
            'code' => $p->code,
            'name' => $p->name,
            'start_date' => Fmt::date($p->start_date),
            'end_date' => Fmt::date($p->end_date),
            'pay_date' => Fmt::date($p->pay_date),
            'status' => $p->status->value,
            'adjusts_payroll_period_id' => $p->adjusts_payroll_period_id,
            'adjustment_reason' => $p->adjustment_reason,
            'notes' => $p->notes,
            'run' => $p->relationLoaded('run') && $p->run ? self::run($p->run) : null,
            'created_at' => Fmt::iso($p->created_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function run(PayrollRun $r): array
    {
        $who = fn (?int $id, string $rel) => $id === null ? null : ['id' => $id, 'name' => $r->relationLoaded($rel) ? $r->{$rel}?->name : null];

        return [
            'id' => $r->id,
            'payroll_period_id' => $r->payroll_period_id,
            'status' => $r->status->value,
            'allowed_actions' => PayrollStateMachine::availableActions($r->status),
            'currency' => $r->currency,
            'decimals' => $r->decimals,
            'employee_count' => $r->employee_count,
            'total_gross' => $r->total_gross,
            'total_deductions' => $r->total_deductions,
            'total_net' => $r->total_net,
            'negative_net_count' => $r->negative_net_count,
            'summary' => $r->summary,
            'processing_started_at' => Fmt::iso($r->processing_started_at),
            'generated_by' => $who($r->generated_by, 'generatedBy'),
            'generated_at' => Fmt::iso($r->generated_at),
            'submitted_by' => $who($r->submitted_by, 'submittedBy'),
            'submitted_at' => Fmt::iso($r->submitted_at),
            'approved_by' => $who($r->approved_by, 'approvedBy'),
            'approved_at' => Fmt::iso($r->approved_at),
            'finalized_by' => $who($r->finalized_by, 'finalizedBy'),
            'finalized_at' => Fmt::iso($r->finalized_at),
            'paid_by' => $who($r->paid_by, 'paidBy'),
            'paid_at' => Fmt::iso($r->paid_at),
            'payment_reference' => $r->payment_reference,
            'cancelled_at' => Fmt::iso($r->cancelled_at),
            'cancel_reason' => $r->cancel_reason,
            'return_reason' => $r->return_reason,
        ];
    }

    /** @return array<string, mixed> */
    public static function item(PayrollItem $i, bool $withLines = false): array
    {
        $out = [
            'id' => $i->id,
            'payroll_run_id' => $i->payroll_run_id,
            'employee_id' => $i->employee_id,
            'adjusts_payroll_item_id' => $i->adjusts_payroll_item_id,
            'employee' => [
                'employee_number' => $i->employee_number,
                'full_name' => $i->employee_name,
                'department' => $i->department_name,
                'position' => $i->position_name,
                'branch' => $i->branch_name,
                'employment_type' => $i->employment_type,
                'join_date' => Fmt::date($i->join_date),
                'terminated_at' => Fmt::date($i->terminated_at),
            ],
            'inputs' => [
                'salary_reference_date' => Fmt::date($i->salary_reference_date),
                'working_days' => $i->working_days,
                'present_days' => $i->present_days,
                'overtime_minutes' => $i->overtime_minutes,
                'unpaid_leave_days' => $i->unpaid_leave_days,
            ],
            'basic_salary' => $i->basic_salary,
            'gross_pay' => $i->gross_pay,
            'total_deductions' => $i->total_deductions,
            'net_pay' => $i->net_pay,
            'has_negative_net' => $i->has_negative_net,
            'warnings' => $i->warnings ?? [],
        ];

        if ($withLines) {
            $lines = $i->relationLoaded('lines') ? $i->lines : $i->lines()->get();
            $out['earnings'] = $lines->filter(fn ($l) => $l->kind->value === 'EARNING')->map(fn ($l) => self::line($l))->values()->all();
            $out['deductions'] = $lines->filter(fn ($l) => $l->kind->value === 'DEDUCTION')->map(fn ($l) => self::line($l))->values()->all();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function line(PayrollItemLine $l): array
    {
        return [
            'id' => $l->id,
            'salary_component_id' => $l->salary_component_id,
            'code' => $l->component_code,
            'name' => $l->component_name,
            'kind' => $l->kind->value,
            'calculation' => $l->calculation,
            'rate' => $l->rate,
            'quantity' => $l->quantity,
            'unit_amount' => $l->unit_amount,
            'base_amount' => $l->base_amount,
            'amount' => $l->amount,
            'description' => $l->description,
            'adjustment_reason' => $l->adjustment_reason,
            // Earning-source lines (e.g. overtime): hours x rate x multiplier = amount, and the paid records.
            'source' => $l->source_key === null ? null : [
                'key' => $l->source_key,
                'minutes' => $l->minutes,
                'hours' => $l->quantity,
                'rate' => $l->unit_amount,
                'multiplier' => $l->multiplier,
                'amount' => $l->amount,
                'refs' => array_map(fn (array $r) => array_intersect_key($r, array_flip([
                    'type', 'id', 'work_date', 'day_type', 'minutes', 'multiplier', 'amount', 'approved_minutes', 'verified_minutes', 'carried_over',
                ])), $l->source_refs ?? []),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function adjustment(PayrollAdjustment $a): array
    {
        return [
            'id' => $a->id,
            'payroll_period_id' => $a->payroll_period_id,
            'employee' => $a->relationLoaded('employee') ? ['id' => $a->employee->id, 'full_name' => $a->employee->full_name, 'employee_number' => $a->employee->employee_number] : ['id' => $a->employee_id],
            'kind' => $a->kind->value,
            'label' => $a->label,
            'amount' => $a->amount,
            'reason' => $a->reason,
            'created_at' => Fmt::iso($a->created_at),
        ];
    }

    /** Payslip summary (list). Totals come from the frozen payroll item. @return array<string, mixed> */
    public static function payslip(Payslip $s, bool $detail = false): array
    {
        $item = $s->item;
        $run = $item->run;
        $period = $s->period;

        $out = [
            'id' => $s->id,
            'payslip_number' => $s->payslip_number,
            'period' => [
                'id' => $period->id,
                'code' => $period->code,
                'name' => $period->name,
                'type' => $period->type->value,
                'start_date' => Fmt::date($period->start_date),
                'end_date' => Fmt::date($period->end_date),
                'pay_date' => Fmt::date($period->pay_date),
            ],
            'status' => $run->status->value,
            'currency' => $run->currency,
            'decimals' => $run->decimals,
            'gross_pay' => $item->gross_pay,
            'total_deductions' => $item->total_deductions,
            'net_pay' => $item->net_pay,
            'issued_at' => Fmt::iso($s->issued_at),
            'has_pdf' => $s->hasFile(),
            'pdf_url' => url("/api/v1/payslips/{$s->id}/pdf"),
        ];

        if ($detail) {
            $out['company'] = array_intersect_key($run->company_snapshot ?? [], array_flip(['name', 'legal_name', 'address']));
            $out['item'] = self::item($item, true);
        }

        return $out;
    }
}
