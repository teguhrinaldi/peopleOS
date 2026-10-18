<?php

namespace App\Services\Admin;

use App\Enums\PayrollStatus;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\Money;
use App\Services\Payroll\PayrollSettings;
use App\Services\Payroll\PayrollStateMachine;
use App\Support\CompanyContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read model for the payroll pages. Reads only: every write goes through the Payroll domain services.
 * Money is always a string and is formatted with the run's own currency and decimals.
 */
class PayrollDesk
{
    /** The forward path shown as a stepper. */
    public const STEPS = ['DRAFT' => 'Draft', 'REVIEW' => 'Review', 'APPROVED' => 'Approved', 'FINALIZED' => 'Finalized', 'PAID' => 'Paid'];

    /** workflow action => [label, permission(s) any-of, button variant, icon] */
    private const ACTIONS = [
        'generate' => ['Generate payroll', ['payroll.manage'], 'primary', 'refresh-cw'],
        'submit' => ['Submit for review', ['payroll.manage'], 'primary', 'send'],
        'approve' => ['Approve', ['payroll.approve'], 'primary', 'circle-check'],
        'return_to_draft' => ['Return to draft', ['payroll.manage', 'payroll.approve'], 'outline', 'undo-2'],
        'finalize' => ['Finalize payroll', ['payroll.finalize'], 'primary', 'lock'],
        'mark_paid' => ['Mark as paid', ['payroll.finalize'], 'primary', 'banknote'],
        'cancel' => ['Cancel period', ['payroll.manage'], 'danger-soft', 'ban'],
    ];

    public function __construct(
        private readonly CompanyContext $context,
        private readonly PayrollSettings $settings,
    ) {}

    /** @param array{status?: ?string, type?: ?string, year?: ?int|string|null} $filters */
    public function periods(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = PayrollPeriod::query()->where('company_id', $this->context->id())->with('run');
        if (! empty($filters['status']) && PayrollStatus::tryFrom((string) $filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['type']) && in_array($filters['type'], ['REGULAR', 'ADJUSTMENT'], true)) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['year'])) {
            $query->whereYear('start_date', (int) $filters['year']);
        }

        return $query->orderByDesc('start_date')->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    /** @return list<int> years that have periods (for the year filter) */
    public function years(): array
    {
        return PayrollPeriod::query()->where('company_id', $this->context->id())
            ->selectRaw('YEAR(start_date) as y')->distinct()->orderByDesc('y')->pluck('y')->map(fn ($y) => (int) $y)->all();
    }

    /** Stepper model: each forward step is done, current or upcoming; CANCELLED replaces the path. */
    public function stepper(PayrollPeriod $period): array
    {
        $status = $period->status->value;
        $keys = array_keys(self::STEPS);
        // PROCESSING is a transient state of DRAFT/REVIEW: show it on the Draft step with a note.
        $position = $status === 'PROCESSING' ? 0 : array_search($status, $keys, true);

        $steps = [];
        foreach (self::STEPS as $key => $label) {
            $index = array_search($key, $keys, true);
            $state = $status === 'CANCELLED' ? 'upcoming' : ($position !== false && $index < $position ? 'done' : ($index === $position ? 'current' : 'upcoming'));
            if ($status === 'PAID' && $key === 'PAID') {
                $state = 'done';
            }
            $steps[] = ['key' => $key, 'label' => $label, 'state' => $state];
        }

        return $steps;
    }

    /**
     * Buttons for the current state: only what the state machine allows AND the viewer's permissions cover.
     * The service re-checks both on submit, so a stale page can never do more than it shows.
     *
     * @return list<array{action: string, label: string, variant: string, icon: string}>
     */
    public function actionsFor(User $viewer, PayrollPeriod $period): array
    {
        $run = $period->run;
        if (! $run) {
            return [];
        }
        $allowed = PayrollStateMachine::availableActions($run->status);
        $out = [];
        foreach (self::ACTIONS as $action => [$label, $permissions, $variant, $icon]) {
            if (! in_array($action, $allowed, true) || ! $viewer->hasAnyPermission(...$permissions)) {
                continue;
            }
            if ($action === 'return_to_draft' && $run->status === PayrollStatus::Approved && ! $viewer->hasPermission('payroll.approve')) {
                continue;
            }
            $out[] = ['action' => $action, 'label' => $label, 'variant' => $variant, 'icon' => $icon];
        }

        return $out;
    }

    public function items(PayrollRun $run, ?string $q, int $perPage = 25): LengthAwarePaginator
    {
        $query = PayrollItem::query()->where('payroll_run_id', $run->id);
        if ($q !== null && $q !== '') {
            $term = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('employee_name', 'like', $term)->orWhere('employee_number', 'like', $term)->orWhere('department_name', 'like', $term));
        }

        return $query->orderBy('employee_name')->orderBy('id')->paginate($perPage)->withQueryString();
    }

    /** Money display for a run: ["IDR 8,000,000", ...]. Values stay strings end to end. */
    public static function money(?string $value, ?PayrollRun $run, bool $withCurrency = true): string
    {
        if ($value === null) {
            return '—';
        }
        $decimals = $run?->decimals ?? 2;
        $formatted = Money::format((string) $value, $decimals);

        return $withCurrency && $run?->currency ? $run->currency.' '.$formatted : $formatted;
    }

    /** A plain amount (no currency) with the company's payroll decimals, from a decimal string. */
    public static function plain(?string $value): string
    {
        return $value === null ? '—' : Money::format($value, app(PayrollSettings::class)->decimals());
    }

    public function defaultCurrency(): string
    {
        return $this->settings->currency();
    }

    /** Draft adjustment period the viewer can still add entries to. */
    public function canEditEntries(PayrollPeriod $period): bool
    {
        return $period->isAdjustment() && $period->status === PayrollStatus::Draft;
    }

    /** Is this period locked for editing (FINALIZED / PAID)? */
    public function isLocked(PayrollPeriod $period): bool
    {
        return $period->status->isLocked();
    }
}
