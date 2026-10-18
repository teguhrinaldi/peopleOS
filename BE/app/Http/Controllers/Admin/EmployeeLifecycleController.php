<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeEmployeeStatusRequest;
use App\Http\Requests\Admin\ReassignEmployeeRequest;
use App\Http\Requests\Admin\TerminateEmployeeRequest;
use App\Models\Employee;
use App\Services\Admin\EmployeeManager;
use App\Services\EmployeeService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;

/** Status changes, dated re-assignment and termination. The rules live in EmployeeService. */
class EmployeeLifecycleController extends Controller
{
    public function status(ChangeEmployeeStatusRequest $request, Employee $employee, EmployeeService $employees): RedirectResponse
    {
        $to = EmployeeStatus::from($request->validated('status'));
        $employees->changeStatus($employee, $to, $request->user());

        return back()->with('success', $to === EmployeeStatus::Active ? "{$employee->full_name} is now active." : "{$employee->full_name} was deactivated.");
    }

    public function reassign(ReassignEmployeeRequest $request, Employee $employee, EmployeeManager $manager): RedirectResponse
    {
        $data = $request->validated();
        $manager->reassign($employee, $data, $data['effective_from'], $request->user());

        return redirect()->route('admin.employees.show', [$employee, 'tab' => 'employment'])
            ->with('success', 'The new assignment starts on '.Carbon::parse($data['effective_from'])->format('d M Y').'.');
    }

    public function terminate(TerminateEmployeeRequest $request, Employee $employee, EmployeeService $employees): RedirectResponse
    {
        $employees->terminate($employee, $request->validated('terminated_at'), $request->validated('reason'), $request->user());

        return redirect()->route('admin.employees.show', $employee)->with('success', "{$employee->full_name}'s employment was ended.");
    }
}
