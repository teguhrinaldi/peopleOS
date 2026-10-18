<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\AttendancePolicyController;
use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\Admin\CorrectionController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\EmployeeLeaveController;
use App\Http\Controllers\Admin\EmployeeLifecycleController;
use App\Http\Controllers\Admin\EmployeeSalaryController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\LeaveRequestController;
use App\Http\Controllers\Admin\LeaveTypeController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\OvertimeController;
use App\Http\Controllers\Admin\PayrollController;
use App\Http\Controllers\Admin\PayslipController;
use App\Http\Controllers\Admin\PlaceholderController;
use App\Http\Controllers\Admin\PositionController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SalaryController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\ShiftAssignmentController;
use App\Http\Controllers\Admin\ShiftController;
use App\Http\Controllers\Admin\WorkLocationController;
use App\Support\AdminNavigation;
use Illuminate\Support\Facades\Route;

/*
| Web admin routes (prefix /admin, names admin.*). The `permission:` middleware is any-of; policies
| and AccessScope then narrow it to the records the user may touch.
*/

Route::get('/', DashboardController::class)->name('dashboard');
Route::get('dashboard/trend', [DashboardController::class, 'trend'])->name('dashboard.trend');

// ---- People: employees ---------------------------------------------------------------------
Route::middleware('permission:employee.create')->group(function () {
    Route::get('employees/create', [EmployeeController::class, 'create'])->name('employees.create');
    Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
});

Route::middleware('permission:employee.view,employee.view_team')->group(function () {
    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->whereNumber('employee')->name('employees.show');
});

Route::middleware('permission:employee.edit')->group(function () {
    Route::get('employees/{employee}/edit', [EmployeeController::class, 'edit'])->whereNumber('employee')->name('employees.edit');
    Route::put('employees/{employee}', [EmployeeController::class, 'update'])->whereNumber('employee')->name('employees.update');
    Route::post('employees/{employee}/status', [EmployeeLifecycleController::class, 'status'])->whereNumber('employee')->name('employees.status');
    Route::post('employees/{employee}/assignments', [EmployeeLifecycleController::class, 'reassign'])->whereNumber('employee')->name('employees.reassign');
    Route::post('employees/{employee}/terminate', [EmployeeLifecycleController::class, 'terminate'])->whereNumber('employee')->name('employees.terminate');
});

Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])
    ->middleware('permission:employee.delete')->whereNumber('employee')->name('employees.destroy');

// ---- People: departments, positions, organization ------------------------------------------
Route::middleware('permission:organization.view')->group(function () {
    Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::get('positions', [PositionController::class, 'index'])->name('positions.index');
    Route::get('organization', [OrganizationController::class, 'show'])->name('organization.show');
});

Route::middleware('permission:organization.manage')->group(function () {
    Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::put('departments/{department}', [DepartmentController::class, 'update'])->whereNumber('department')->name('departments.update');
    Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->whereNumber('department')->name('departments.destroy');

    Route::post('positions', [PositionController::class, 'store'])->name('positions.store');
    Route::put('positions/{position}', [PositionController::class, 'update'])->whereNumber('position')->name('positions.update');
    Route::delete('positions/{position}', [PositionController::class, 'destroy'])->whereNumber('position')->name('positions.destroy');

    Route::put('organization/company', [OrganizationController::class, 'updateCompany'])->name('organization.company');
    Route::post('organization/branches', [OrganizationController::class, 'storeBranch'])->name('organization.branches.store');
    Route::put('organization/branches/{branch}', [OrganizationController::class, 'updateBranch'])->whereNumber('branch')->name('organization.branches.update');
    Route::delete('organization/branches/{branch}', [OrganizationController::class, 'destroyBranch'])->whereNumber('branch')->name('organization.branches.destroy');
});

// ---- Attendance: board, records, selfies, review, corrections ------------------------------
Route::middleware('permission:attendance.view,attendance.view_team')->group(function () {
    Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance');
    Route::get('attendance/export', [AttendanceController::class, 'export'])->name('attendance.export');
    Route::get('attendance/{attendance}', [AttendanceController::class, 'show'])->whereNumber('attendance')->name('attendance.show');
    Route::get('attendance/events/{event}/selfie', [AttendanceController::class, 'selfie'])->whereNumber('event')->name('attendance.selfie');
});
Route::post('attendance/{attendance}/review', [AttendanceController::class, 'review'])
    ->middleware('permission:attendance.review')->whereNumber('attendance')->name('attendance.review');

Route::middleware('permission:attendance.correct,attendance.approve_team')->group(function () {
    Route::get('attendance/corrections', [CorrectionController::class, 'index'])->name('attendance.corrections');
    Route::post('attendance/corrections/{correction}/decision', [CorrectionController::class, 'decide'])->whereNumber('correction')->name('attendance.corrections.decide');
});

// ---- Overtime ------------------------------------------------------------------------------
Route::get('overtime', [OvertimeController::class, 'index'])
    ->middleware('permission:overtime.view,overtime.approve,overtime.approve_team')->name('overtime');
Route::post('overtime/{overtimeRequest}/decision', [OvertimeController::class, 'decide'])
    ->middleware('permission:overtime.approve,overtime.approve_team')->whereNumber('overtimeRequest')->name('overtime.decide');

// ---- Shifts, overrides, working calendars, holidays ----------------------------------------
Route::middleware('permission:attendance.manage')->group(function () {
    Route::get('shifts', [ShiftController::class, 'index'])->name('shifts');
    Route::get('shifts/create', [ShiftController::class, 'create'])->name('shifts.create');
    Route::post('shifts', [ShiftController::class, 'store'])->name('shifts.store');
    Route::get('shifts/{shift}/edit', [ShiftController::class, 'edit'])->whereNumber('shift')->name('shifts.edit');
    Route::put('shifts/{shift}', [ShiftController::class, 'update'])->whereNumber('shift')->name('shifts.update');
    Route::delete('shifts/{shift}', [ShiftController::class, 'destroy'])->whereNumber('shift')->name('shifts.destroy');

    Route::get('shifts/assignments', [ShiftAssignmentController::class, 'index'])->name('shifts.assignments');
    Route::post('shifts/assignments', [ShiftAssignmentController::class, 'store'])->name('shifts.assignments.store');
    Route::delete('shifts/assignments/{shiftAssignment}', [ShiftAssignmentController::class, 'destroy'])->whereNumber('shiftAssignment')->name('shifts.assignments.destroy');

    Route::get('shifts/calendars', [CalendarController::class, 'index'])->name('shifts.calendars');
    Route::post('shifts/calendars', [CalendarController::class, 'store'])->name('shifts.calendars.store');
    Route::put('shifts/calendars/{calendar}', [CalendarController::class, 'update'])->whereNumber('calendar')->name('shifts.calendars.update');
    Route::delete('shifts/calendars/{calendar}', [CalendarController::class, 'destroy'])->whereNumber('calendar')->name('shifts.calendars.destroy');
    Route::post('shifts/calendars/assignments', [CalendarController::class, 'assign'])->name('shifts.calendars.assign');
    Route::delete('shifts/calendars/assignments/{calendarAssignment}', [CalendarController::class, 'unassign'])->whereNumber('calendarAssignment')->name('shifts.calendars.unassign');

    Route::get('shifts/holidays', [HolidayController::class, 'index'])->name('shifts.holidays');
    Route::post('shifts/holidays', [HolidayController::class, 'store'])->name('shifts.holidays.store');
    Route::put('shifts/holidays/{holiday}', [HolidayController::class, 'update'])->whereNumber('holiday')->name('shifts.holidays.update');
    Route::delete('shifts/holidays/{holiday}', [HolidayController::class, 'destroy'])->whereNumber('holiday')->name('shifts.holidays.destroy');

    // ---- Work locations and attendance policies ------------------------------------------------
    Route::get('work-locations', [WorkLocationController::class, 'index'])->name('work-locations');
    Route::get('work-locations/create', [WorkLocationController::class, 'create'])->name('work-locations.create');
    Route::post('work-locations', [WorkLocationController::class, 'store'])->name('work-locations.store');
    Route::get('work-locations/{location}/edit', [WorkLocationController::class, 'edit'])->whereNumber('location')->name('work-locations.edit');
    Route::put('work-locations/{location}', [WorkLocationController::class, 'update'])->whereNumber('location')->name('work-locations.update');
    Route::delete('work-locations/{location}', [WorkLocationController::class, 'destroy'])->whereNumber('location')->name('work-locations.destroy');

    Route::get('work-locations/policies', [AttendancePolicyController::class, 'index'])->name('work-locations.policies');
    Route::post('work-locations/policies', [AttendancePolicyController::class, 'store'])->name('work-locations.policies.store');
    Route::put('work-locations/policies/{policy}', [AttendancePolicyController::class, 'update'])->whereNumber('policy')->name('work-locations.policies.update');
    Route::delete('work-locations/policies/{policy}', [AttendancePolicyController::class, 'destroy'])->whereNumber('policy')->name('work-locations.policies.destroy');
});

// ---- Activity (read-only) and settings -----------------------------------------------------
Route::get('activity', ActivityController::class)->middleware('permission:activity.view')->name('activity');

Route::middleware('permission:settings.manage')->group(function () {
    Route::get('settings', [SettingsController::class, 'edit'])->name('settings');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::put('settings/attendance', [SettingsController::class, 'attendance'])->name('settings.attendance');
    Route::get('settings/roles', [SettingsController::class, 'roles'])->name('settings.roles');
});

// ---- Leave ---------------------------------------------------------------------------------
Route::middleware('permission:leave.view,leave.view_team,leave.approve,leave.approve_team')->group(function () {
    Route::get('leave-requests', [LeaveRequestController::class, 'index'])->name('leave-requests');
    Route::get('leave-requests/{leaveRequest}', [LeaveRequestController::class, 'show'])->whereNumber('leaveRequest')->name('leave-requests.show');
    Route::post('leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->whereNumber('leaveRequest')->name('leave-requests.cancel');
});
Route::post('leave-requests/{leaveRequest}/decision', [LeaveRequestController::class, 'decide'])
    ->middleware('permission:leave.approve,leave.approve_team')->whereNumber('leaveRequest')->name('leave-requests.decide');

Route::middleware('permission:leave.manage')->group(function () {
    Route::get('leave-types', [LeaveTypeController::class, 'index'])->name('leave-types');
    Route::get('leave-types/create', [LeaveTypeController::class, 'create'])->name('leave-types.create');
    Route::post('leave-types', [LeaveTypeController::class, 'store'])->name('leave-types.store');
    Route::get('leave-types/{leaveType}/edit', [LeaveTypeController::class, 'edit'])->whereNumber('leaveType')->name('leave-types.edit');
    Route::put('leave-types/{leaveType}', [LeaveTypeController::class, 'update'])->whereNumber('leaveType')->name('leave-types.update');
    Route::delete('leave-types/{leaveType}', [LeaveTypeController::class, 'destroy'])->whereNumber('leaveType')->name('leave-types.destroy');
    Route::post('leave-types/{leaveType}/policies', [LeaveTypeController::class, 'savePolicy'])->whereNumber('leaveType')->name('leave-types.policies.save');
    Route::delete('leave-types/{leaveType}/policies/{policy}', [LeaveTypeController::class, 'destroyPolicy'])->whereNumber(['leaveType', 'policy'])->name('leave-types.policies.destroy');

    Route::post('employees/{employee}/leave', [EmployeeLeaveController::class, 'store'])->whereNumber('employee')->name('employees.leave.store');
    Route::post('employees/{employee}/leave-balances/{balance}/adjust', [EmployeeLeaveController::class, 'adjust'])->whereNumber(['employee', 'balance'])->name('employees.leave.adjust');
});

// ---- Payroll -------------------------------------------------------------------------------
// No delete routes exist for periods, runs, items or lines: payroll is cancelled, finalized, adjusted, never edited away.
Route::middleware('permission:payroll.view')->group(function () {
    Route::get('payroll', [PayrollController::class, 'index'])->name('payroll');
    Route::get('payroll/{period}', [PayrollController::class, 'show'])->whereNumber('period')->name('payroll.show');
    Route::get('payroll/{period}/items/{item}', [PayrollController::class, 'item'])->whereNumber(['period', 'item'])->name('payroll.items.show');
});
Route::middleware('permission:payroll.manage')->group(function () {
    Route::post('payroll', [PayrollController::class, 'store'])->name('payroll.store');
    Route::put('payroll/{period}', [PayrollController::class, 'update'])->whereNumber('period')->name('payroll.update');
    Route::post('payroll/{period}/generate', [PayrollController::class, 'transition'])->defaults('action', 'generate')->whereNumber('period')->name('payroll.generate');
    Route::post('payroll/{period}/submit', [PayrollController::class, 'transition'])->defaults('action', 'submit')->whereNumber('period')->name('payroll.submit');
    Route::post('payroll/{period}/cancel', [PayrollController::class, 'transition'])->defaults('action', 'cancel')->whereNumber('period')->name('payroll.cancel');
    Route::post('payroll/{period}/adjustments', [PayrollController::class, 'adjustment'])->whereNumber('period')->name('payroll.adjustments.store');
    Route::post('payroll/{period}/adjustment-entries', [PayrollController::class, 'addEntry'])->whereNumber('period')->name('payroll.entries.store');
    Route::delete('payroll/{period}/adjustment-entries/{entry}', [PayrollController::class, 'removeEntry'])->whereNumber(['period', 'entry'])->name('payroll.entries.destroy');
});
Route::post('payroll/{period}/approve', [PayrollController::class, 'transition'])->defaults('action', 'approve')
    ->middleware('permission:payroll.approve')->whereNumber('period')->name('payroll.approve');
Route::post('payroll/{period}/return-to-draft', [PayrollController::class, 'transition'])->defaults('action', 'return_to_draft')
    ->middleware('permission:payroll.manage,payroll.approve')->whereNumber('period')->name('payroll.return-to-draft');
Route::middleware('permission:payroll.finalize')->group(function () {
    Route::post('payroll/{period}/finalize', [PayrollController::class, 'transition'])->defaults('action', 'finalize')->whereNumber('period')->name('payroll.finalize');
    Route::post('payroll/{period}/mark-paid', [PayrollController::class, 'transition'])->defaults('action', 'mark_paid')->whereNumber('period')->name('payroll.mark-paid');
});

// ---- Salary structures ---------------------------------------------------------------------
// Employee salary history is append-only: only POST (change from a date); no update/delete route exists.
Route::middleware('permission:salary.view')->group(function () {
    Route::get('salary-structures', [SalaryController::class, 'index'])->name('salary-structures');
    Route::get('salary-structures/components', [SalaryController::class, 'components'])->name('salary-structures.components');
});
Route::middleware('permission:salary.manage')->group(function () {
    Route::get('salary-structures/create', [SalaryController::class, 'create'])->name('salary-structures.create');
    Route::post('salary-structures', [SalaryController::class, 'store'])->name('salary-structures.store');
    Route::post('salary-structures/components', [SalaryController::class, 'saveComponent'])->name('salary-structures.components.store');
    Route::put('salary-structures/components/{component}', [SalaryController::class, 'saveComponent'])->whereNumber('component')->name('salary-structures.components.update');
    Route::delete('salary-structures/components/{component}', [SalaryController::class, 'destroyComponent'])->whereNumber('component')->name('salary-structures.components.destroy');
    Route::get('salary-structures/{structure}/edit', [SalaryController::class, 'edit'])->whereNumber('structure')->name('salary-structures.edit');
    Route::put('salary-structures/{structure}', [SalaryController::class, 'update'])->whereNumber('structure')->name('salary-structures.update');
    Route::delete('salary-structures/{structure}', [SalaryController::class, 'destroy'])->whereNumber('structure')->name('salary-structures.destroy');
    Route::post('salary-structures/{structure}/assign', [SalaryController::class, 'assign'])->whereNumber('structure')->name('salary-structures.assign');
    Route::post('employees/{employee}/salary', [EmployeeSalaryController::class, 'store'])->whereNumber('employee')->name('employees.salary.store');
});
Route::get('salary-structures/{structure}', [SalaryController::class, 'show'])->middleware('permission:salary.view')->whereNumber('structure')->name('salary-structures.show');

// ---- Payslips ------------------------------------------------------------------------------
// The PDF is streamed from the private disk after PayslipPolicy; the stored path is never exposed.
Route::middleware('permission:payslip.view')->group(function () {
    Route::get('payslips', [PayslipController::class, 'index'])->name('payslips');
    Route::get('payslips/{payslip}', [PayslipController::class, 'show'])->whereNumber('payslip')->name('payslips.show');
    Route::get('payslips/{payslip}/pdf', [PayslipController::class, 'pdf'])->whereNumber('payslip')->name('payslips.pdf');
});
Route::post('payslips/{payslip}/regenerate', [PayslipController::class, 'regenerate'])->middleware('permission:payroll.finalize')->whereNumber('payslip')->name('payslips.regenerate');

// ---- Documents -----------------------------------------------------------------------------
// Files are streamed from the private disk after DocumentPolicy; invisible documents answer 404.
Route::middleware('permission:document.view,document.manage')->group(function () {
    Route::get('documents', [DocumentController::class, 'index'])->name('documents');
    Route::get('documents/types', [DocumentController::class, 'types'])->middleware('permission:document.manage')->name('documents.types');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->whereNumber('document')->name('documents.show');
    Route::get('documents/{document}/file', [DocumentController::class, 'file'])->whereNumber('document')->name('documents.file');
});
Route::middleware('permission:document.manage')->group(function () {
    Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::post('documents/types', [DocumentController::class, 'saveType'])->name('documents.types.store');
    Route::put('documents/types/{type}', [DocumentController::class, 'saveType'])->whereNumber('type')->name('documents.types.update');
    Route::put('documents/{document}', [DocumentController::class, 'update'])->whereNumber('document')->name('documents.update');
    Route::post('documents/{document}/archive', [DocumentController::class, 'archive'])->whereNumber('document')->name('documents.archive');
    Route::post('documents/{document}/restore', [DocumentController::class, 'restore'])->whereNumber('document')->name('documents.restore');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->whereNumber('document')->name('documents.destroy');
});

// ---- Reports -------------------------------------------------------------------------------
// report.view plus the domain permission of each report (ReportService hides what the viewer may not run).
Route::middleware('permission:report.view')->group(function () {
    Route::get('reports', [ReportController::class, 'index'])->name('reports');
    Route::get('reports/{key}', [ReportController::class, 'show'])->where('key', '[a-z]+\.[a-z_]+')->name('reports.show');
    Route::get('reports/{key}/export/{format}', [ReportController::class, 'export'])->where('key', '[a-z]+\.[a-z_]+')->whereIn('format', ['csv', 'pdf'])->name('reports.export');
});

// ---- Notifications (the top-bar bell) -------------------------------------------------------
Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereNumber('notification')->name('notifications.read');
Route::get('notifications', [NotificationController::class, 'index'])->name('notifications');

// ---- Modules that arrive later: one designed placeholder each ------------------------------
// Build a module by registering its real route under the same name; delete its `placeholder` key
// in App\Support\AdminNavigation so this loop stops registering the stub.
foreach (AdminNavigation::placeholders() as $item) {
    Route::get($item['key'], PlaceholderController::class)
        ->defaults('page', $item['key'])
        ->middleware('permission:'.implode(',', $item['permissions']))
        ->name(substr($item['route'], strlen('admin.')));
}
