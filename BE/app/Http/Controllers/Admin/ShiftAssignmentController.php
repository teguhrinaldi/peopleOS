<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ShiftAssignmentRequest;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Services\Admin\ShiftSetupService;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Date-ranged shift overrides (take precedence over the shift in the employee's placement). */
class ShiftAssignmentController extends Controller
{
    public function index(Request $request, CompanyContext $context): View
    {
        $today = $context->today()->toDateString();
        $state = in_array($request->query('state'), ['current', 'upcoming', 'past'], true) ? $request->query('state') : 'current';

        $assignments = ShiftAssignment::query()
            ->whereHas('employee', fn ($q) => $q->where('company_id', $context->id()))
            ->with(['employee:id,full_name,employee_number,photo_path', 'shift'])
            ->when($state === 'current', fn ($q) => $q->where('start_date', '<=', $today)->where(fn ($w) => $w->whereNull('end_date')->orWhere('end_date', '>=', $today)))
            ->when($state === 'upcoming', fn ($q) => $q->where('start_date', '>', $today))
            ->when($state === 'past', fn ($q) => $q->where('end_date', '<', $today))
            ->orderBy($state === 'past' ? 'end_date' : 'start_date', $state === 'past' ? 'desc' : 'asc')
            ->paginate(25)->withQueryString();

        return view('admin.shifts.assignments', [
            'assignments' => $assignments,
            'state' => $state,
            'today' => $today,
            'employees' => Employee::query()->where('company_id', $context->id())->where('status', '!=', EmployeeStatus::Terminated->value)
                ->orderBy('full_name')->get(['id', 'full_name', 'employee_number'])->mapWithKeys(fn ($e) => [$e->id => "{$e->full_name} ({$e->employee_number})"])->all(),
            'shifts' => Shift::query()->where('company_id', $context->id())->where('is_active', true)->orderBy('start_time')->get()
                ->mapWithKeys(fn (Shift $s) => [$s->id => "{$s->name} ({$s->startLabel()}–{$s->endLabel()})"])->all(),
        ]);
    }

    public function store(ShiftAssignmentRequest $request, ShiftSetupService $shifts): RedirectResponse
    {
        $shifts->assign($request->validated(), $request->user());

        return redirect()->route('admin.shifts.assignments', ['state' => $request->validated('start_date') > app(CompanyContext::class)->today()->toDateString() ? 'upcoming' : 'current'])
            ->with('success', 'Shift override saved.');
    }

    public function destroy(Request $request, ShiftAssignment $shiftAssignment, ShiftSetupService $shifts): RedirectResponse
    {
        $result = $shifts->endAssignment($shiftAssignment, $request->user());

        return back()->with('success', $result === 'deleted' ? 'The upcoming override was removed.' : 'The override now ends today.');
    }
}
