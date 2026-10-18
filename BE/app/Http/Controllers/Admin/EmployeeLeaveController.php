<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustBalanceRequest;
use App\Http\Requests\Admin\FileLeaveRequest;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Services\Admin\LeaveActions;
use Illuminate\Http\RedirectResponse;

/** HR actions from the employee Leave tab (permission leave.manage, employee within scope). */
class EmployeeLeaveController extends Controller
{
    public function store(FileLeaveRequest $request, Employee $employee, LeaveActions $actions): RedirectResponse
    {
        $leave = $actions->fileOnBehalf($request->user(), $employee, $request->validated());

        return redirect()->route('admin.employees.show', [$employee, 'tab' => 'leave'])
            ->with('success', "Leave filed for {$employee->full_name} ({$leave->days} day(s)); it is now waiting for review.");
    }

    public function adjust(AdjustBalanceRequest $request, Employee $employee, LeaveBalance $balance, LeaveActions $actions): RedirectResponse
    {
        abort_unless($balance->employee_id === $employee->id, 404);
        $actions->adjust($request->user(), $balance, $request->delta(), $request->validated('reason'));

        return redirect()->route('admin.employees.show', [$employee, 'tab' => 'leave', 'year' => $balance->year])
            ->with('success', 'Balance adjusted. The change is recorded in the audit log.');
    }
}
