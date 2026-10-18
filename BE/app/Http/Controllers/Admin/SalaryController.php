<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SalaryAssignRequest;
use App\Http\Requests\Admin\SalaryComponentRequest;
use App\Http\Requests\Admin\SalaryStructureRequest;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Services\Admin\SalaryActions;
use App\Services\Admin\SalaryDesk;
use App\Services\Payroll\Money;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Salary components and structures (salary.view to read, salary.manage to write). Writes go through SalaryActions. */
class SalaryController extends Controller
{
    public function __construct(private readonly SalaryDesk $desk, private readonly SalaryActions $actions, private readonly CompanyContext $context) {}

    public function index(Request $request): View
    {
        return view('admin.salary.structures', ['structures' => $this->desk->structures(), 'canManage' => $request->user()->hasPermission('salary.manage')]);
    }

    public function components(Request $request): View
    {
        return view('admin.salary.components', ['components' => $this->desk->components(), 'canManage' => $request->user()->hasPermission('salary.manage'), 'kinds' => SalaryDesk::KINDS, 'calculations' => SalaryDesk::CALCULATIONS]);
    }

    public function show(Request $request, SalaryStructure $structure): View
    {
        $this->own($structure);
        $structure->load('lines.component');
        $canManage = $request->user()->hasPermission('salary.manage');

        return view('admin.salary.show', [
            'structure' => $structure,
            'canManage' => $canManage,
            'employees' => $canManage ? $this->desk->assignableEmployees($request->user()) : [],
            'assigned' => $this->desk->structures()->firstWhere('id', $structure->id)?->assigned_count ?? 0,
        ]);
    }

    public function create(): View
    {
        return view('admin.salary.form', ['structure' => null, 'choices' => $this->desk->componentChoices(), 'lines' => []]);
    }

    public function edit(SalaryStructure $structure): View
    {
        $this->own($structure);
        $structure->load('lines.component');

        return view('admin.salary.form', [
            'structure' => $structure,
            'choices' => $this->desk->componentChoices(false),
            'lines' => $structure->lines->map(fn ($l) => ['salary_component_id' => $l->salary_component_id, 'amount' => $l->amount, 'rate' => $l->rate !== null ? Money::trimRate((string) $l->rate) : null])->values()->all(),
        ]);
    }

    public function store(SalaryStructureRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $structure = $this->actions->saveStructure($request->user(), null, $data, $data['components'] ?? []);

        return redirect()->route('admin.salary-structures.show', $structure)->with('success', "{$structure->name} was created. Assign it to employees from a date to start using it.");
    }

    public function update(SalaryStructureRequest $request, SalaryStructure $structure): RedirectResponse
    {
        $this->own($structure);
        $data = $request->validated();
        $this->actions->saveStructure($request->user(), $structure, $data, $data['components'] ?? []);

        return redirect()->route('admin.salary-structures.show', $structure)->with('success', 'Structure saved. Employees already assigned keep their own dated values; assign again to apply the new template.');
    }

    public function destroy(Request $request, SalaryStructure $structure): RedirectResponse
    {
        $this->own($structure);
        $this->actions->deleteStructure($request->user(), $structure);

        return redirect()->route('admin.salary-structures')->with('success', "{$structure->name} was deleted. Employee salary history was kept.");
    }

    public function assign(SalaryAssignRequest $request, SalaryStructure $structure): RedirectResponse
    {
        $this->own($structure);
        $result = $this->actions->assign($request->user(), $structure, array_map('intval', $request->validated('employee_ids')), $request->validated('effective_from'), $request->validated('reason'));

        $back = redirect()->route('admin.salary-structures.show', $structure);
        if ($result['failed'] !== []) {
            $back->with('warning', count($result['done']).' assigned, '.count($result['failed']).' could not be changed.')
                ->with('flash_items', array_map(fn ($name, $why) => "{$name}: {$why}", array_keys($result['failed']), $result['failed']));
        } else {
            $back->with('success', count($result['done']).' employee(s) now use '.$structure->name.' from '.$request->validated('effective_from').'.'.($result['warnings'] ? ' Some changes fall inside finalized payroll, which is never recalculated; use an adjustment period for differences.' : ''));
        }

        return $back;
    }

    public function saveComponent(SalaryComponentRequest $request, ?SalaryComponent $component = null): RedirectResponse
    {
        if ($component) {
            abort_unless($component->company_id === $this->context->id(), 404);
        }
        $saved = $this->actions->saveComponent($request->user(), $component, $request->componentData());

        return redirect()->route('admin.salary-structures.components')->with('success', $component ? "{$saved->name} was saved." : "{$saved->name} was added to the catalogue.");
    }

    public function destroyComponent(Request $request, SalaryComponent $component): RedirectResponse
    {
        abort_unless($component->company_id === $this->context->id(), 404);
        $this->actions->deleteComponent($request->user(), $component);

        return redirect()->route('admin.salary-structures.components')->with('success', "{$component->name} was deleted.");
    }

    private function own(SalaryStructure $structure): void
    {
        abort_unless($structure->company_id === $this->context->id(), 404);
    }
}
