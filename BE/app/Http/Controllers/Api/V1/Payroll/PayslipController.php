<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Enums\PayrollStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\PayrollResources;
use App\Models\Payslip;
use App\Services\Payroll\PayslipDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Employee self-service payslips (payslip.view_own: own + FINALIZED/PAID only) and HR access (payslip.view: all).
 * The PDF is streamed from the private disk after the policy check; its path never leaves the server.
 * Unknown or foreign ids answer 404 so ids cannot be probed.
 */
class PayslipController extends ApiController
{
    public function __construct(private readonly PayslipDocuments $documents) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'scope' => ['nullable', 'in:mine,all'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'employee_id' => ['nullable', 'integer'],
            'payroll_period_id' => ['nullable', 'integer'],
        ]);
        $user = $request->user();

        $query = Payslip::query()
            ->with(['period', 'item.run'])
            ->whereIn('payroll_item_id', fn ($q) => $q->select('payroll_items.id')->from('payroll_items')
                ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')
                ->whereIn('payroll_runs.status', [PayrollStatus::Finalized->value, PayrollStatus::Paid->value]));

        if ($request->query('scope', 'mine') === 'all') {
            if (! $user->hasPermission('payslip.view')) {
                throw ApiException::make('FORBIDDEN', 'You cannot view other employees\' payslips.', 403);
            }
            if ($request->filled('employee_id')) {
                $query->where('employee_id', $request->integer('employee_id'));
            }
        } else {
            if (! $user->hasAnyPermission('payslip.view_own', 'payslip.view')) {
                throw ApiException::make('FORBIDDEN', 'You do not have permission to perform this action.', 403);
            }
            $query->where('employee_id', $this->employee($request)->id);
        }

        if ($request->filled('payroll_period_id')) {
            $query->where('payroll_period_id', $request->integer('payroll_period_id'));
        }
        if ($request->filled('year')) {
            $query->whereIn('payroll_period_id', fn ($q) => $q->select('id')->from('payroll_periods')->whereYear('start_date', $request->integer('year')));
        }

        return $this->paginated(
            $query->orderByDesc('issued_at')->orderByDesc('id')->paginate($this->perPage($request)),
            fn ($s) => PayrollResources::payslip($s),
        );
    }

    public function show(Request $request, int $payslip): JsonResponse
    {
        return $this->ok(PayrollResources::payslip($this->authorized($request, $payslip)->load(['period', 'item.run', 'item.lines']), true));
    }

    public function pdf(Request $request, int $payslip): StreamedResponse
    {
        return $this->documents->stream($this->authorized($request, $payslip), $request->boolean('download'));
    }

    private function authorized(Request $request, int $id): Payslip
    {
        $slip = Payslip::query()->find($id);
        if (! $slip || ! $request->user()->can('view', $slip)) {
            throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $slip;
    }
}
