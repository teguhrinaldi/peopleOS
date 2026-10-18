<?php

namespace App\Http\Resources;

use App\Enums\ExpenseStatus as S;
use App\Models\ExpenseCategory;
use App\Models\ExpensePayment;
use App\Models\ExpensePolicy;
use App\Models\ExpenseRequest;
use App\Models\ExpenseRequestEvent;
use App\Models\User;
use App\Policies\ExpenseRequestPolicy;
use App\Support\Fmt;

/** Plain array mappers. receipt_disk / receipt_path never appear: the receipt is only reachable through receipt.download_url. */
final class ExpenseResources
{
    public const WITH = ['employee', 'category', 'reviewer', 'payment'];

    /** @return array<string, mixed> */
    public static function category(ExpenseCategory $c, bool $admin = false): array
    {
        return [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'description' => $c->description,
            'is_active' => $c->is_active, 'receipt_required' => $c->receipt_required,
            'max_amount_per_claim' => $c->max_amount_per_claim, 'max_amount_per_month' => $c->max_amount_per_month, 'max_age_days' => $c->max_age_days,
        ] + ($admin ? ['sort_order' => $c->sort_order] : []);
    }

    /** @return array<string, mixed> */
    public static function policy(ExpensePolicy $p, int $effectiveMaxKb): array
    {
        return [
            'approval_route' => $p->approval_route, 'payment_route' => $p->payment_route,
            'max_amount_per_claim' => $p->max_amount_per_claim, 'max_amount_per_month' => $p->max_amount_per_month,
            'max_receipt_kb' => $p->max_receipt_kb, 'effective_max_receipt_kb' => $effectiveMaxKb,
            'allowed_receipt_types' => ['pdf', 'jpg', 'png'],
        ];
    }

    /** @return array<string, mixed> */
    public static function request(ExpenseRequest $r, User $viewer): array
    {
        $employee = $r->relationLoaded('employee') ? $r->employee : null;

        return [
            'id' => $r->id,
            'request_number' => $r->request_number,
            'status' => $r->status->value,
            'employee' => $employee ? ['id' => $employee->id, 'full_name' => $employee->full_name, 'employee_number' => $employee->employee_number] : ['id' => $r->employee_id],
            'category' => $r->relationLoaded('category') && $r->category ? ['id' => $r->category->id, 'code' => $r->category->code, 'name' => $r->category->name] : ['id' => $r->expense_category_id],
            'expense_date' => Fmt::date($r->expense_date),
            'currency' => $r->currency,
            'submitted_amount' => (string) $r->submitted_amount,
            'approved_amount' => $r->approved_amount === null ? null : (string) $r->approved_amount,
            'business_purpose' => $r->business_purpose,
            'description' => $r->description,
            'receipt' => $r->hasReceipt() ? [
                'name' => $r->receipt_original_name, 'extension' => $r->receipt_extension, 'mime_type' => $r->receipt_mime,
                'size_bytes' => $r->receipt_size_bytes, 'sha256' => $r->receipt_sha256,
                'download_url' => '/api/v1/reimbursements/'.$r->id.'/receipt',
            ] : null,
            'submitted_at' => Fmt::iso($r->submitted_at),
            'review_started_at' => Fmt::iso($r->review_started_at),
            'reviewed_at' => Fmt::iso($r->reviewed_at),
            'reviewer' => $r->relationLoaded('reviewer') && $r->reviewer ? ['id' => $r->reviewer->id, 'name' => $r->reviewer->name] : null,
            'review_note' => $r->review_note,
            'rejection_reason' => $r->rejection_reason,
            'adjustment_reason' => $r->adjustment_reason,
            'cancelled_at' => Fmt::iso($r->cancelled_at),
            'cancel_reason' => $r->cancel_reason,
            'payment_route' => $r->payment_route,
            'scheduled_for' => Fmt::date($r->scheduled_for),
            'paid_at' => Fmt::iso($r->paid_at),
            'payment' => $r->relationLoaded('payment') && $r->payment ? self::payment($r->payment) : null,
            'payroll' => $r->payroll_item_line_id ? ['payroll_period_id' => $r->payroll_period_id, 'included_at' => Fmt::iso($r->payroll_included_at)] : null,
            'actions' => self::actions($r, $viewer),
            'created_at' => Fmt::iso($r->created_at),
            'updated_at' => Fmt::iso($r->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function payment(ExpensePayment $p): array
    {
        return [
            'id' => $p->id, 'amount' => (string) $p->amount, 'currency' => $p->currency, 'method' => $p->method, 'reference' => $p->reference,
            'paid_at' => Fmt::iso($p->paid_at), 'paid_by' => $p->relationLoaded('payer') && $p->payer ? ['id' => $p->payer->id, 'name' => $p->payer->name] : $p->paid_by,
            'note' => $p->note, 'idempotency_key' => $p->idempotency_key,
        ];
    }

    /** @return array<string, mixed> */
    public static function event(ExpenseRequestEvent $e): array
    {
        return [
            'id' => $e->id, 'action' => $e->action, 'from_status' => $e->from_status, 'to_status' => $e->to_status,
            'actor' => $e->relationLoaded('actor') && $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : ($e->actor_id ? ['id' => $e->actor_id] : null),
            'note' => $e->note, 'data' => $e->data, 'at' => Fmt::iso($e->created_at),
        ];
    }

    /** What this viewer may do right now, so clients never re-implement the rules. @return array<string, bool> */
    public static function actions(ExpenseRequest $r, User $viewer): array
    {
        $policy = app(ExpenseRequestPolicy::class);
        $s = $r->status;
        $payrollRouted = $r->payment_route === 'PAYROLL' || $r->payroll_item_line_id !== null;

        $edit = $s === S::Draft && $policy->update($viewer, $r);
        $decide = $policy->approve($viewer, $r);
        $pay = $policy->pay($viewer, $r) && ! $payrollRouted;

        return [
            'can_edit' => $edit,
            'can_submit' => $edit,
            'can_withdraw' => in_array($s, [S::Draft, S::Submitted, S::UnderReview], true) && $policy->withdraw($viewer, $r),
            'can_start_review' => $decide && $s === S::Submitted,
            'can_approve' => $decide && $s === S::UnderReview,
            'can_reject' => $decide && $s === S::UnderReview,
            'can_schedule' => $pay && $s === S::Approved,
            'can_pay' => $pay && $s === S::ScheduledForPayment,
            'can_cancel' => ! $s->isTerminal() && $r->payroll_item_line_id === null && $policy->cancel($viewer, $r),
        ];
    }
}
