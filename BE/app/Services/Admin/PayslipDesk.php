<?php

namespace App\Services\Admin;

use App\Enums\PayrollStatus;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Payroll\PayslipDocuments;
use App\Support\CompanyContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payslips for the admin: a searchable list across periods and employees plus the per-employee list.
 * Visibility is the payslip.view permission (checked by the route) and PayslipPolicy (checked per record);
 * the query itself is company-scoped and only ever contains released (FINALIZED / PAID) payroll.
 */
class PayslipDesk
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly PayslipDocuments $documents,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array{q?: ?string, period_id?: ?int|string, year?: ?int|string, employee_id?: ?int|string} $filters */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage)->withQueryString();
    }

    /** @param array<string, mixed> $filters */
    public function query(array $filters): Builder
    {
        $query = Payslip::query()
            ->with(['period', 'employee:id,full_name,employee_number', 'item:id,payroll_run_id,employee_name,employee_number,department_name,gross_pay,total_deductions,net_pay', 'item.run:id,currency,decimals,status'])
            ->whereIn('payroll_period_id', fn ($q) => $q->select('id')->from('payroll_periods')->where('company_id', $this->context->id()))
            ->whereIn('payroll_item_id', fn ($q) => $q->select('payroll_items.id')->from('payroll_items')
                ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')
                ->whereIn('payroll_runs.status', [PayrollStatus::Finalized->value, PayrollStatus::Paid->value]));

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }
        if (! empty($filters['period_id'])) {
            $query->where('payroll_period_id', (int) $filters['period_id']);
        }
        if (! empty($filters['year'])) {
            $query->whereIn('payroll_period_id', fn ($q) => $q->select('id')->from('payroll_periods')->whereYear('start_date', (int) $filters['year']));
        }
        if (! empty($filters['q'])) {
            $term = '%'.addcslashes((string) $filters['q'], '%_\\').'%';
            $query->where(fn (Builder $w) => $w->where('payslip_number', 'like', $term)
                ->orWhereIn('payroll_item_id', fn ($q) => $q->select('id')->from('payroll_items')
                    ->where(fn ($i) => $i->where('employee_name', 'like', $term)->orWhere('employee_number', 'like', $term))));
        }

        return $query->orderByDesc('issued_at')->orderByDesc('id');
    }

    /** @return array<int, string> period id => name, for the filter */
    public function periodOptions(): array
    {
        return PayrollPeriod::query()->where('company_id', $this->context->id())
            ->whereIn('status', [PayrollStatus::Finalized->value, PayrollStatus::Paid->value])
            ->orderByDesc('start_date')->pluck('name', 'id')->all();
    }

    /** @return list<int> */
    public function years(): array
    {
        return PayrollPeriod::query()->where('company_id', $this->context->id())
            ->whereIn('status', [PayrollStatus::Finalized->value, PayrollStatus::Paid->value])
            ->selectRaw('YEAR(start_date) as y')->distinct()->orderByDesc('y')->pluck('y')->map(fn ($y) => (int) $y)->all();
    }

    /** A payslip the user may see, or null: callers answer 404 either way so ids cannot be probed. */
    public function findVisible(User $user, int $id): ?Payslip
    {
        $slip = Payslip::query()->with(['period', 'employee', 'item.run', 'item.lines'])->find($id);
        if (! $slip || $slip->period?->company_id !== $this->context->id() || ! $user->can('view', $slip)) {
            return null;
        }

        return $slip;
    }

    /** Re-render a missing PDF from the stored snapshot. Returns true when a file was written (and audited). */
    public function regenerateMissing(User $actor, Payslip $payslip): bool
    {
        $written = $this->documents->ensure($payslip);
        if ($written) {
            $this->activity->log('payslip.pdf_regenerated', $payslip, null, ['payslip_number' => $payslip->payslip_number], null, $actor);
        }

        return $written;
    }

    /** Payslip PDF for one employee, newest first (employee detail tab). */
    public function forEmployee(Employee $employee, int $limit = 24)
    {
        return $this->query(['employee_id' => $employee->id])->limit($limit)->get();
    }
}
