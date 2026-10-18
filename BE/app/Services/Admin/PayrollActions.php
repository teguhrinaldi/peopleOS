<?php

namespace App\Services\Admin;

use App\Enums\SalaryComponentKind;
use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\Payroll\Money;
use App\Services\Payroll\PayrollGenerator;
use App\Services\Payroll\PayrollPeriodService;
use App\Services\Payroll\PayrollWorkflow;
use App\Support\CompanyContext;
use Illuminate\Validation\ValidationException;

/**
 * Web entry point for every payroll write. Permissions, the state machine, locking and audit all live in the
 * Payroll domain services; this class only turns their errors into messages a payroll clerk can act on.
 */
class PayrollActions
{
    /** Domain error => form field, for errors that belong to a specific input. */
    private const FIELDS = [
        'PERIOD_OVERLAP' => 'start_date', 'INVALID_PERIOD' => 'end_date', 'REASON_REQUIRED' => 'reason',
        'EMPLOYEE_NOT_IN_ORIGINAL_PAYROLL' => 'employee_id', 'INVALID_ADJUSTMENT_TARGET' => 'employee_id',
    ];

    public function __construct(
        private readonly PayrollWorkflow $workflow,
        private readonly PayrollGenerator $generator,
        private readonly PayrollPeriodService $periods,
        private readonly CompanyContext $context,
    ) {}

    /**
     * Run a workflow action. Returns the success message, or throws PayrollActionFailed with a friendly message
     * (and, for negative net pay, the employees to look at). Permission failures stay 403.
     *
     * @param  array{reason?: ?string, payment_reference?: ?string}  $input
     */
    public function perform(User $actor, PayrollPeriod $period, string $action, array $input = []): string
    {
        try {
            match ($action) {
                'generate' => $this->generator->generate($actor, $period),
                'submit' => $this->workflow->submit($actor, $period),
                'approve' => $this->workflow->approve($actor, $period),
                'return_to_draft' => $this->workflow->returnToDraft($actor, $period, (string) ($input['reason'] ?? '')),
                'finalize' => $this->workflow->finalize($actor, $period),
                'mark_paid' => $this->workflow->markPaid($actor, $period, $input['payment_reference'] ?? null),
                'cancel' => $this->workflow->cancel($actor, $period, (string) ($input['reason'] ?? '')),
            };
        } catch (ApiException $e) {
            throw $this->friendly($e, $period);
        }

        return match ($action) {
            'generate' => 'Payroll was generated from the current attendance, leave and salary data. Review the totals and warnings below.',
            'submit' => 'Payroll was submitted for review.',
            'approve' => 'Payroll was approved. It can now be finalized.',
            'return_to_draft' => 'Payroll was returned to draft. Generate it again if the data changed, then resubmit.',
            'finalize' => 'Payroll was finalized and payslips were issued. It is now read-only.',
            'mark_paid' => 'Payroll was marked as paid.',
            'cancel' => 'The payroll period was cancelled.',
        };
    }

    /** @param array<string, mixed> $data */
    public function createPeriod(User $actor, array $data): PayrollPeriod
    {
        return $this->field(fn () => $this->periods->createRegular(
            $actor, $data['start_date'], $data['end_date'], $data['name'] ?? null, $data['pay_date'] ?? null, $data['notes'] ?? null,
        ));
    }

    /** @param array<string, mixed> $data */
    public function updatePeriod(User $actor, PayrollPeriod $period, array $data): PayrollPeriod
    {
        return $this->field(fn () => $this->periods->update($actor, $period, $data));
    }

    public function createAdjustment(User $actor, PayrollPeriod $original, string $reason, ?string $name, ?string $payDate): PayrollPeriod
    {
        return $this->field(fn () => $this->periods->createAdjustment($actor, $original, $reason, $name, $payDate), $original);
    }

    /** @param array{employee_id: int, kind: string, label: string, amount: string, reason: string} $data */
    public function addEntry(User $actor, PayrollPeriod $period, array $data): PayrollAdjustment
    {
        $employee = Employee::query()->withTrashed()->where('company_id', $this->context->id())->find((int) $data['employee_id']);
        if (! $employee) {
            throw ValidationException::withMessages(['employee_id' => 'Choose an employee from the list.']);
        }

        return $this->field(fn () => $this->periods->addAdjustmentEntry(
            $actor, $period, $employee, SalaryComponentKind::from($data['kind']), $data['label'], Money::of((string) $data['amount']), $data['reason'],
        ), $period);
    }

    public function removeEntry(User $actor, PayrollPeriod $period, PayrollAdjustment $entry): void
    {
        $this->field(fn () => $this->periods->removeAdjustmentEntry($actor, $period, $entry), $period);
    }

    /** Field-level domain errors become form errors; everything else becomes a friendly page message. */
    private function field(callable $work, ?PayrollPeriod $period = null): mixed
    {
        try {
            return $work();
        } catch (ApiException $e) {
            if (isset(self::FIELDS[$e->errorCode])) {
                throw ValidationException::withMessages([self::FIELDS[$e->errorCode] => $e->getMessage()]);
            }
            if ($e->errorCode === 'VALIDATION_ERROR' && $e->details !== []) {
                throw ValidationException::withMessages($e->details);
            }
            throw $this->friendly($e, $period);
        }
    }

    private function friendly(ApiException $e, ?PayrollPeriod $period): \Throwable
    {
        $run = $period?->run()->first();
        $message = match ($e->errorCode) {
            'SEPARATE_APPROVER_REQUIRED' => 'A different person must approve this payroll. The company requires the approver to be someone other than the person who generated or submitted it. Ask another approver to continue.',
            'PAYROLL_PROCESSING' => 'Payroll is being generated right now. Wait a moment and refresh the page before taking another action.',
            'INVALID_STATE_TRANSITION' => $this->staleStateMessage($e),
            'PAYROLL_LOCKED' => 'Finalized and paid payroll is locked and cannot be changed. To correct it, create an adjustment period instead.',
            'NEGATIVE_NET_PAY' => 'Payroll cannot be approved while some employees would receive a negative net pay. Fix their salary or deductions, generate again, then resubmit.',
            'PAYROLL_EMPTY' => 'Generate the payroll first. It must include at least one employee before it can be submitted.',
            'PERIOD_NOT_FINALIZED' => 'Only finalized or paid payroll can be adjusted.',
            default => $e->getMessage(),
        };

        $items = [];
        if ($e->errorCode === 'NEGATIVE_NET_PAY') {
            foreach ((array) ($e->details['employees'] ?? []) as $row) {
                $items[] = trim(($row['employee_number'] ?? '').' '.($row['employee_name'] ?? '')).': '.PayrollDesk::money((string) ($row['net_pay'] ?? '0'), $run);
            }
        }

        return new PayrollActionFailed($message, $items, $e);
    }

    private function staleStateMessage(ApiException $e): string
    {
        $status = strtolower((string) ($e->details['status'] ?? 'its current state'));
        $action = str_replace('_', ' ', (string) ($e->details['action'] ?? 'do that'));

        return "This payroll is now {$status}, so it cannot {$action}. Someone else may have changed it; the page shows the current state.";
    }
}
