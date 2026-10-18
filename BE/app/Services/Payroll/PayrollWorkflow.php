<?php

namespace App\Services\Payroll;

use App\Enums\PayrollStatus;
use App\Exceptions\ApiException;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Payroll\Earnings\EarningSources;
use Illuminate\Support\Facades\DB;

/**
 * Submit, approve, return, finalize, mark paid, cancel. Every action: one transaction, period+run locked
 * FOR UPDATE, permission re-checked, transition validated by PayrollStateMachine (via PayrollTransitions),
 * audit logged. A double submission therefore finds the new state and gets 409 INVALID_STATE_TRANSITION.
 */
class PayrollWorkflow
{
    public function __construct(
        private readonly PayrollTransitions $transitions,
        private readonly PayrollSettings $settings,
        private readonly PayslipIssuer $issuer,
        private readonly PayslipDocuments $documents,
        private readonly ActivityLogger $activity,
        private readonly EarningSources $sources,
    ) {}

    public function submit(User $actor, PayrollPeriod $period): PayrollRun
    {
        $this->transitions->requirePermission($actor, 'payroll.manage');

        return DB::transaction(function () use ($actor, $period) {
            [$p, $run] = $this->transitions->lock($period);
            PayrollStateMachine::transition('submit', $run->status);

            if ($run->generated_at === null || $run->employee_count === 0) {
                throw ApiException::make('PAYROLL_EMPTY', 'Generate the payroll (with at least one employee) before submitting it.', 422);
            }

            $from = $run->status;
            $this->transitions->apply($p, $run, 'submit', [
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'return_reason' => null,
            ]);
            $this->log('payroll.submitted', $run, $p, $from, $actor);

            return $run;
        });
    }

    public function approve(User $actor, PayrollPeriod $period): PayrollRun
    {
        $this->transitions->requirePermission($actor, 'payroll.approve');

        return DB::transaction(function () use ($actor, $period) {
            [$p, $run] = $this->transitions->lock($period);
            PayrollStateMachine::transition('approve', $run->status);

            $preparers = array_map('intval', array_filter([$run->generated_by, $run->submitted_by]));
            if ($this->settings->requireSeparateApprover() && in_array((int) $actor->id, $preparers, true)) {
                throw ApiException::make(
                    'SEPARATE_APPROVER_REQUIRED',
                    'Payroll must be approved by someone other than the person who generated or submitted it.',
                    403,
                );
            }

            $negative = PayrollItem::query()->where('payroll_run_id', $run->id)->where('has_negative_net', true)
                ->orderBy('employee_number')->get(['employee_id', 'employee_number', 'employee_name', 'net_pay']);
            if ($negative->isNotEmpty()) {
                throw ApiException::make('NEGATIVE_NET_PAY', 'Some employees have a negative net pay. Correct their components and regenerate before approving.', 422, [
                    'employees' => $negative->map(fn ($i) => [
                        'employee_id' => $i->employee_id, 'employee_number' => $i->employee_number,
                        'employee_name' => $i->employee_name, 'net_pay' => (string) $i->net_pay,
                    ])->all(),
                ]);
            }

            $from = $run->status;
            $this->transitions->apply($p, $run, 'approve', [
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);
            $this->log('payroll.approved', $run, $p, $from, $actor);

            return $run;
        });
    }

    /** REVIEW -> DRAFT (preparer recall or approver rejection) and APPROVED -> DRAFT (approval withdrawn, payroll.approve). */
    public function returnToDraft(User $actor, PayrollPeriod $period, string $reason): PayrollRun
    {
        if (! $actor->hasAnyPermission('payroll.manage', 'payroll.approve')) {
            $this->transitions->requirePermission($actor, 'payroll.approve');
        }
        if (trim($reason) === '') {
            throw ApiException::make('REASON_REQUIRED', 'A reason is required to return payroll to draft.');
        }

        return DB::transaction(function () use ($actor, $period, $reason) {
            [$p, $run] = $this->transitions->lock($period);
            PayrollStateMachine::transition('return_to_draft', $run->status);
            if ($run->status === PayrollStatus::Approved) {
                $this->transitions->requirePermission($actor, 'payroll.approve');
            }

            // Earning-source records (overtime, ...) become available again; the stale items must be regenerated
            // before the run can be submitted again (generated_at cleared), so nothing is paid without its inclusion.
            $released = $this->releaseSources($run);

            $from = $run->status;
            $this->transitions->apply($p, $run, 'return_to_draft', ($released > 0 ? ['generated_at' => null] : []) + [
                'return_reason' => $reason,
                'submitted_by' => null,
                'submitted_at' => null,
                'approved_by' => null,
                'approved_at' => null,
            ]);
            $this->log('payroll.returned', $run, $p, $from, $actor, ['reason' => $reason]);

            return $run;
        });
    }

    /** APPROVED -> FINALIZED: freezes the run and issues payslip numbers; PDFs are rendered after commit. */
    public function finalize(User $actor, PayrollPeriod $period): PayrollRun
    {
        $this->transitions->requirePermission($actor, 'payroll.finalize');

        $run = DB::transaction(function () use ($actor, $period) {
            [$p, $run] = $this->transitions->lock($period);
            PayrollStateMachine::transition('finalize', $run->status);

            $issued = $this->issuer->issue($p, $run);
            foreach ($this->sources->all() as $source) {
                $source->freezeRun($run);
            }
            $from = $run->status;
            $this->transitions->apply($p, $run, 'finalize', [
                'finalized_by' => $actor->id,
                'finalized_at' => now(),
            ]);
            $this->log('payroll.finalized', $run, $p, $from, $actor, [
                'employees' => $run->employee_count,
                'payslips_issued' => $issued,
            ]);

            return $run;
        });

        // Outside the transaction: a PDF problem never undoes a finalization; `payroll:payslips` repairs it later.
        $this->documents->generateForRun($run);

        return $run->refresh();
    }

    public function markPaid(User $actor, PayrollPeriod $period, ?string $reference = null): PayrollRun
    {
        $this->transitions->requirePermission($actor, 'payroll.finalize');

        return DB::transaction(function () use ($actor, $period, $reference) {
            [$p, $run] = $this->transitions->lock($period);
            $from = $run->status;
            $this->transitions->apply($p, $run, 'mark_paid', [
                'paid_by' => $actor->id,
                'paid_at' => now(),
                'payment_reference' => $reference,
            ]);
            $this->log('payroll.paid', $run, $p, $from, $actor, ['payment_reference' => $reference]);

            return $run;
        });
    }

    public function cancel(User $actor, PayrollPeriod $period, string $reason): PayrollRun
    {
        $this->transitions->requirePermission($actor, 'payroll.manage');
        if (trim($reason) === '') {
            throw ApiException::make('REASON_REQUIRED', 'A reason is required to cancel payroll.');
        }

        return DB::transaction(function () use ($actor, $period, $reason) {
            [$p, $run] = $this->transitions->lock($period);
            PayrollStateMachine::transition('cancel', $run->status);
            $this->releaseSources($run);
            $from = $run->status;
            $this->transitions->apply($p, $run, 'cancel', [
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);
            $this->log('payroll.cancelled', $run, $p, $from, $actor, ['reason' => $reason]);

            return $run;
        });
    }

    private function releaseSources(PayrollRun $run): int
    {
        $released = 0;
        foreach ($this->sources->all() as $source) {
            $released += $source->releaseRun($run);
        }

        return $released;
    }

    /** @param array<string, mixed> $extra */
    private function log(string $action, PayrollRun $run, PayrollPeriod $period, PayrollStatus $from, User $actor, array $extra = []): void
    {
        $this->activity->log($action, $run, ['status' => $from->value], ['period' => $period->code, 'status' => $run->status->value] + $extra, null, $actor);
    }
}
