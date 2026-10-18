<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Enums\SalaryComponentKind;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Payroll\AdjustmentEntryRequest;
use App\Http\Requests\Api\Payroll\MarkPaidRequest;
use App\Http\Requests\Api\Payroll\PayrollPeriodRequest;
use App\Http\Requests\Api\Payroll\ReasonRequest;
use App\Http\Resources\PayrollResources;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollPeriod;
use App\Services\Payroll\PayrollGenerator;
use App\Services\Payroll\PayrollPeriodService;
use App\Services\Payroll\PayrollWorkflow;
use App\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payroll periods and their workflow. Reading needs payroll.view; every action re-checks its own permission
 * in the service (generate/submit/cancel: payroll.manage, approve: payroll.approve, finalize/mark-paid: payroll.finalize).
 * There is deliberately NO delete route: periods are cancelled, finalized payroll is immutable.
 */
class PayrollPeriodController extends ApiController
{
    public function __construct(
        private readonly PayrollPeriodService $periods,
        private readonly PayrollGenerator $generator,
        private readonly PayrollWorkflow $workflow,
        private readonly CompanyContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'in:DRAFT,PROCESSING,REVIEW,APPROVED,FINALIZED,PAID,CANCELLED'],
            'type' => ['nullable', 'in:REGULAR,ADJUSTMENT'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $query = PayrollPeriod::query()->where('company_id', $this->context->id())->with('run');
        foreach (['status', 'type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('year')) {
            $query->whereYear('start_date', (int) $request->query('year'));
        }

        return $this->paginated(
            $query->orderByDesc('start_date')->orderByDesc('id')->paginate($this->perPage($request)),
            fn ($p) => PayrollResources::period($p),
        );
    }

    public function show(PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorize('view', $payrollPeriod);

        return $this->ok($this->present($payrollPeriod));
    }

    public function store(PayrollPeriodRequest $request): JsonResponse
    {
        $period = $this->periods->createRegular(
            $request->user(),
            $request->validated('start_date'),
            $request->validated('end_date'),
            $request->validated('name'),
            $request->validated('pay_date'),
            $request->validated('notes'),
        );

        return $this->ok($this->present($period), 201);
    }

    public function update(PayrollPeriodRequest $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorize('manage', $payrollPeriod);
        $period = $this->periods->update($request->user(), $payrollPeriod, $request->safe()->only(['name', 'pay_date', 'notes']));

        return $this->ok($this->present($period));
    }

    public function generate(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorize('view', $payrollPeriod);
        $this->generator->generate($request->user(), $payrollPeriod);

        return $this->ok($this->present($payrollPeriod));
    }

    public function submit(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorize('view', $payrollPeriod);
        $this->workflow->submit($request->user(), $payrollPeriod);

        return $this->ok($this->present($payrollPeriod));
    }

    public function approve(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorize('view', $payrollPeriod);
        $this->workflow->approve($request->user(), $payrollPeriod);

        return $this->ok($this->present($payrollPeriod));
    }

    public function returnToDraft(ReasonRequest $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorize('view', $payrollPeriod);
        $this->workflow->returnToDraft($request->user(), $payrollPeriod, $request->validated('reason'));

        return $this->ok($this->present($payrollPeriod));
    }

    public function finalize(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorize('view', $payrollPeriod);
        $this->workflow->finalize($request->user(), $payrollPeriod);

        return $this->ok($this->present($payrollPeriod));
    }

    public function markPaid(MarkPaidRequest $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorize('view', $payrollPeriod);
        $this->workflow->markPaid($request->user(), $payrollPeriod, $request->validated('payment_reference'));

        return $this->ok($this->present($payrollPeriod));
    }

    public function cancel(ReasonRequest $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorize('view', $payrollPeriod);
        $this->workflow->cancel($request->user(), $payrollPeriod, $request->validated('reason'));

        return $this->ok($this->present($payrollPeriod));
    }

    /** Create an ADJUSTMENT period that corrects this finalized/paid one. */
    public function createAdjustment(ReasonRequest $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorize('view', $payrollPeriod);
        $period = $this->periods->createAdjustment(
            $request->user(), $payrollPeriod, $request->validated('reason'), $request->validated('name'), $request->validated('pay_date'),
        );

        return $this->ok($this->present($period), 201);
    }

    public function adjustmentEntries(PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorize('view', $payrollPeriod);

        return $this->ok($payrollPeriod->adjustments()->with('employee')->get()->map(fn ($a) => PayrollResources::adjustment($a))->values());
    }

    public function addAdjustmentEntry(AdjustmentEntryRequest $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorize('manage', $payrollPeriod);
        $employee = Employee::query()->withTrashed()->where('company_id', $this->context->id())->find($request->integer('employee_id'));
        if (! $employee) {
            throw ApiException::make('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        $entry = $this->periods->addAdjustmentEntry(
            $request->user(), $payrollPeriod, $employee, SalaryComponentKind::from($request->validated('kind')),
            $request->validated('label'), (string) $request->validated('amount'), $request->validated('reason'),
        );

        return $this->ok(PayrollResources::adjustment($entry->load('employee')), 201);
    }

    /** Removes a manual entry of a DRAFT adjustment period (never anything finalized). */
    public function removeAdjustmentEntry(Request $request, PayrollPeriod $payrollPeriod, PayrollAdjustment $entry): JsonResponse
    {
        $this->authorize('manage', $payrollPeriod);
        $this->periods->removeAdjustmentEntry($request->user(), $payrollPeriod, $entry);

        return $this->ok(null);
    }

    /** @return array<string, mixed> */
    private function present(PayrollPeriod $period): array
    {
        $period = $period->fresh(['run.generatedBy', 'run.submittedBy', 'run.approvedBy', 'run.finalizedBy', 'run.paidBy', 'original', 'adjustmentPeriods']);
        $out = PayrollResources::period($period);
        $out['original'] = $period->original ? ['id' => $period->original->id, 'code' => $period->original->code, 'name' => $period->original->name] : null;
        $out['adjustment_periods'] = $period->adjustmentPeriods->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'status' => $p->status->value])->values();

        return $out;
    }
}
