<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EmployeeSalaryChangeRequest;
use App\Models\Employee;
use App\Services\Admin\SalaryActions;
use Illuminate\Http\RedirectResponse;

/** "Change salary from date" on the employee Payroll tab. History is append-only: there is no edit or delete route. */
class EmployeeSalaryController extends Controller
{
    public function store(EmployeeSalaryChangeRequest $request, Employee $employee, SalaryActions $actions): RedirectResponse
    {
        $changes = $request->changes();
        $back = redirect()->route('admin.employees.show', [$employee, 'tab' => 'payroll']);

        $result = $actions->change($request->user(), $employee, $request->validated('effective_from'), $changes, $request->validated('reason'));
        $back->with('success', 'Salary change saved from '.$request->validated('effective_from').'. Earlier values stay in the history.');
        if ($result['warnings'] !== []) {
            $back->with('warning', 'This date falls inside finalized payroll, which is never recalculated. Create an adjustment period to settle any difference.');
        }

        return $back;
    }
}
