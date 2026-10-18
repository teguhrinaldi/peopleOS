<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PayrollEntryRequest;
use App\Http\Requests\Admin\PayrollPeriodRequest;
use App\Http\Requests\Admin\PayrollReasonRequest;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Services\Admin\PayrollActions;
use App\Services\Admin\PayrollDesk;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Payroll periods, their workflow and the run snapshot. Thin: reads via PayrollDesk, writes via PayrollActions. */
class PayrollController extends Controller
{
    public function __construct(private readonly PayrollDesk $desk, private readonly PayrollActions $actions) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:20'], 'type' => ['nullable', 'string', 'max:20'], 'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        return view('admin.payroll.index', [
            'periods' => $this->desk->periods($filters),
            'years' => $this->desk->years(),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, PayrollPeriod $period, CompanyContext $context): View
    {
        $this->authorizeView($period, $context);
        $period->load(['run.generatedBy', 'run.submittedBy', 'run.approvedBy', 'run.finalizedBy', 'run.paidBy', 'original', 'adjustmentPeriods']);
        $run = $period->run;
        $q = trim((string) $request->query('q', ''));

        return view('admin.payroll.show', [
            'period' => $period,
            'run' => $run,
            'desk' => $this->desk,
            'steps' => $this->desk->stepper($period),
            'actions' => $this->desk->actionsFor($request->user(), $period),
            'items' => $run ? $this->desk->items($run, $q) : null,
            'q' => $q,
            'entries' => $period->isAdjustment() ? $period->adjustments()->with('employee:id,full_name,employee_number')->orderBy('id')->get() : collect(),
            'canManage' => $request->user()->hasPermission('payroll.manage'),
        ]);
    }

    /** One employee's computed line items: a partial for the drawer, a full page when opened directly. */
    public function item(Request $request, PayrollPeriod $period, PayrollItem $item, CompanyContext $context)
    {
        $this->authorizeView($period, $context);
        $period->load('run');
        abort_unless($period->run && $item->payroll_run_id === $period->run->id, 404);
        $item->load('lines', 'payslip');
        $data = ['period' => $period, 'run' => $period->run, 'item' => $item, 'canPayslip' => $request->user()->hasPermission('payslip.view')];

        return $request->ajax() || $request->header('X-Partial') === '1'
            ? view('admin.payroll._item', $data)
            : view('admin.payroll.item', $data);
    }

    public function store(PayrollPeriodRequest $request): RedirectResponse
    {
        $period = $this->actions->createPeriod($request->user(), $request->validated());

        return redirect()->route('admin.payroll.show', $period)->with('success', "{$period->name} was created as a draft. Generate payroll to calculate it.");
    }

    public function update(PayrollPeriodRequest $request, PayrollPeriod $period, CompanyContext $context): RedirectResponse
    {
        abort_unless($period->company_id === $context->id(), 404);
        $this->actions->updatePeriod($request->user(), $period, $request->validated());

        return back()->with('success', 'Period details were saved.');
    }

    /** One route per workflow action; the action name is a route default, the permission a route middleware. */
    public function transition(PayrollReasonRequest $request, PayrollPeriod $period, CompanyContext $context): RedirectResponse
    {
        $this->authorizeView($period, $context);
        $action = (string) $request->route()->defaults['action'];
        $message = $this->actions->perform($request->user(), $period, $action, $request->only(['reason', 'payment_reference']));

        return redirect()->route('admin.payroll.show', $period)->with('success', $message);
    }

    public function adjustment(PayrollReasonRequest $request, PayrollPeriod $period, CompanyContext $context): RedirectResponse
    {
        $this->authorizeView($period, $context);
        $adjustment = $this->actions->createAdjustment($request->user(), $period, $request->validated('reason'), $request->validated('name'), $request->validated('pay_date'));

        return redirect()->route('admin.payroll.show', $adjustment)->with('success', 'Adjustment period created. Add the corrections below, then generate and review it like any payroll.');
    }

    public function addEntry(PayrollEntryRequest $request, PayrollPeriod $period, CompanyContext $context): RedirectResponse
    {
        $this->authorizeView($period, $context);
        $this->actions->addEntry($request->user(), $period, $request->validated());

        return redirect()->route('admin.payroll.show', $period)->with('success', 'Adjustment entry added. Generate the adjustment payroll again to include it.');
    }

    public function removeEntry(Request $request, PayrollPeriod $period, PayrollAdjustment $entry, CompanyContext $context): RedirectResponse
    {
        $this->authorizeView($period, $context);
        abort_unless($entry->payroll_period_id === $period->id, 404);
        $this->actions->removeEntry($request->user(), $period, $entry);

        return redirect()->route('admin.payroll.show', $period)->with('success', 'Adjustment entry removed.');
    }

    private function authorizeView(PayrollPeriod $period, CompanyContext $context): void
    {
        abort_unless($period->company_id === $context->id(), 404);
    }
}
