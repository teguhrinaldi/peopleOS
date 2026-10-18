<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EmployeeIndexRequest;
use App\Http\Requests\Admin\StoreEmployeeRequest;
use App\Http\Requests\Admin\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Services\Admin\DocumentDesk;
use App\Services\Admin\EmployeeDirectory;
use App\Services\Admin\EmployeeFormOptions;
use App\Services\Admin\EmployeeManager;
use App\Services\Admin\EmployeeProfile;
use App\Services\Admin\LeaveDesk;
use App\Services\Admin\PayslipDesk;
use App\Services\Admin\SalaryDesk;
use App\Services\EmployeeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(EmployeeIndexRequest $request, EmployeeDirectory $directory): View
    {
        $filters = $request->filters();

        return view('admin.employees.index', [
            'employees' => $directory->paginate($request->user(), $filters),
            'filters' => $filters,
            'options' => $directory->filterOptions($request->user()),
            'isFiltered' => collect($filters)->except(['sort', 'dir'])->filter()->isNotEmpty(),
        ]);
    }

    public function create(Request $request, EmployeeFormOptions $options): View
    {
        return view('admin.employees.form', ['employee' => null, 'options' => $options->form($request->user())]);
    }

    public function store(StoreEmployeeRequest $request, EmployeeManager $manager): RedirectResponse
    {
        $employee = $manager->create($request->employeeData(), $request->file('photo'), $request->user());

        return redirect()->route('admin.employees.show', $employee)
            ->with('success', "{$employee->full_name} was added.");
    }

    public function show(Request $request, Employee $employee, EmployeeProfile $profile, EmployeeFormOptions $options, LeaveDesk $leave, SalaryDesk $salary, PayslipDesk $payslips, DocumentDesk $documents): View
    {
        Gate::authorize('view', $employee);

        $viewer = $request->user();
        $tabs = $profile->tabsFor($viewer, $employee);
        $tab = collect($tabs)->pluck('key')->contains($request->query('tab')) ? $request->query('tab') : 'overview';
        $employee->load('user.roles');

        return view('admin.employees.show', [
            'employee' => $employee,
            'placement' => $profile->placement($employee),
            'tabs' => $tabs,
            'tab' => $tab,
            'history' => in_array($tab, ['employment'], true) ? $profile->history($employee) : null,
            'attendance' => $tab === 'attendance' ? $profile->attendance($employee) : null,
            'activity' => $tab === 'activity' ? $profile->activity($employee) : null,
            'payroll' => $tab === 'payroll' ? [
                'salary' => $viewer->hasPermission('salary.view') ? $salary->forEmployee($employee) : null,
                'payslips' => $viewer->hasPermission('payslip.view') ? $payslips->forEmployee($employee) : null,
                'choices' => $viewer->hasPermission('salary.manage') ? $salary->componentChoices(false) : [],
            ] : null,
            'docs' => $tab === 'documents' ? ['list' => $documents->forEmployee($viewer, $employee), 'desk' => $documents, 'types' => $documents->uploadTypes()] : null,
            'leave' => $tab === 'leave' ? $leave->employeeSummary($employee, $request->integer('year') ?: null) : null,
            'options' => Gate::allows('update', $employee) && ! $employee->isTerminated() ? $options->assignment($employee) : [],
        ]);
    }

    public function edit(Employee $employee, Request $request, EmployeeProfile $profile, EmployeeFormOptions $options): View|RedirectResponse
    {
        Gate::authorize('update', $employee);

        if ($employee->isTerminated()) {
            return redirect()->route('admin.employees.show', $employee)->with('error', 'Terminated employees are read-only.');
        }

        return view('admin.employees.form', [
            'employee' => $employee->load('user.roles'),
            'placement' => $profile->placement($employee),
            'options' => $options->form($request->user(), $employee),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, EmployeeManager $manager): RedirectResponse
    {
        $manager->update($employee, $request->employeeData(), $request->file('photo'), $request->boolean('remove_photo'), $request->user());

        return redirect()->route('admin.employees.show', $employee)->with('success', 'Employee details saved.');
    }

    public function destroy(Request $request, Employee $employee, EmployeeService $employees): RedirectResponse
    {
        Gate::authorize('delete', $employee);

        $employees->delete($employee, $request->user());

        return redirect()->route('admin.employees.index')->with('success', "{$employee->full_name} was deleted.");
    }
}
