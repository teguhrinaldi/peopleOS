<?php

use App\Http\Controllers\Api\V1\Payroll\EmployeeSalaryController;
use App\Http\Controllers\Api\V1\Payroll\PayrollPeriodController;
use App\Http\Controllers\Api\V1\Payroll\PayrollRunController;
use App\Http\Controllers\Api\V1\Payroll\PayslipController;
use App\Http\Controllers\Api\V1\Payroll\SalaryComponentController;
use App\Http\Controllers\Api\V1\Payroll\SalaryStructureController;
use Illuminate\Support\Facades\Route;

// Included inside the authenticated /api/v1 group in routes/api.php.
// No DELETE route exists for payroll periods, runs, items, lines or payslips (finalized payroll is immutable).

// Employee self-service (+ HR with payslip.view)
Route::get('payslips', [PayslipController::class, 'index'])->name('payslips.index');
Route::get('payslips/{payslip}', [PayslipController::class, 'show'])->whereNumber('payslip')->name('payslips.show');
Route::get('payslips/{payslip}/pdf', [PayslipController::class, 'pdf'])->whereNumber('payslip')->name('payslips.pdf');

// Salary setup
Route::middleware('permission:salary.view,salary.manage')->group(function () {
    Route::get('salary-components', [SalaryComponentController::class, 'index'])->name('salary-components.index');
    Route::get('salary-components/{salaryComponent}', [SalaryComponentController::class, 'show'])->whereNumber('salaryComponent')->name('salary-components.show');
    Route::get('salary-structures', [SalaryStructureController::class, 'index'])->name('salary-structures.index');
    Route::get('salary-structures/{salaryStructure}', [SalaryStructureController::class, 'show'])->whereNumber('salaryStructure')->name('salary-structures.show');
    Route::get('employees/{employee}/salary', [EmployeeSalaryController::class, 'show'])->whereNumber('employee')->name('employee-salary.show');
});
Route::middleware('permission:salary.manage')->group(function () {
    Route::post('salary-components', [SalaryComponentController::class, 'store'])->name('salary-components.store');
    Route::patch('salary-components/{salaryComponent}', [SalaryComponentController::class, 'update'])->whereNumber('salaryComponent')->name('salary-components.update');
    Route::delete('salary-components/{salaryComponent}', [SalaryComponentController::class, 'destroy'])->whereNumber('salaryComponent')->name('salary-components.destroy');
    Route::post('salary-structures', [SalaryStructureController::class, 'store'])->name('salary-structures.store');
    Route::patch('salary-structures/{salaryStructure}', [SalaryStructureController::class, 'update'])->whereNumber('salaryStructure')->name('salary-structures.update');
    Route::delete('salary-structures/{salaryStructure}', [SalaryStructureController::class, 'destroy'])->whereNumber('salaryStructure')->name('salary-structures.destroy');
    Route::post('salary-structures/{salaryStructure}/assign', [SalaryStructureController::class, 'assign'])->whereNumber('salaryStructure')->name('salary-structures.assign');
    Route::post('employees/{employee}/salary', [EmployeeSalaryController::class, 'store'])->whereNumber('employee')->name('employee-salary.store');
});

// Payroll (read: payroll.view; each action re-checks its own permission in the service)
Route::middleware('permission:payroll.view')->group(function () {
    Route::get('payroll-periods', [PayrollPeriodController::class, 'index'])->name('payroll-periods.index');
    Route::post('payroll-periods', [PayrollPeriodController::class, 'store'])->name('payroll-periods.store');
    Route::get('payroll-periods/{payrollPeriod}', [PayrollPeriodController::class, 'show'])->whereNumber('payrollPeriod')->name('payroll-periods.show');
    Route::patch('payroll-periods/{payrollPeriod}', [PayrollPeriodController::class, 'update'])->whereNumber('payrollPeriod')->name('payroll-periods.update');

    foreach (['generate', 'submit', 'approve', 'finalize', 'cancel'] as $action) {
        Route::post("payroll-periods/{payrollPeriod}/{$action}", [PayrollPeriodController::class, $action])->whereNumber('payrollPeriod')->name("payroll-periods.{$action}");
    }
    Route::post('payroll-periods/{payrollPeriod}/return-to-draft', [PayrollPeriodController::class, 'returnToDraft'])->whereNumber('payrollPeriod')->name('payroll-periods.return');
    Route::post('payroll-periods/{payrollPeriod}/mark-paid', [PayrollPeriodController::class, 'markPaid'])->whereNumber('payrollPeriod')->name('payroll-periods.mark-paid');

    Route::post('payroll-periods/{payrollPeriod}/adjustments', [PayrollPeriodController::class, 'createAdjustment'])->whereNumber('payrollPeriod')->name('payroll-periods.adjustments.store');
    Route::get('payroll-periods/{payrollPeriod}/adjustment-entries', [PayrollPeriodController::class, 'adjustmentEntries'])->whereNumber('payrollPeriod')->name('payroll-periods.entries.index');
    Route::post('payroll-periods/{payrollPeriod}/adjustment-entries', [PayrollPeriodController::class, 'addAdjustmentEntry'])->whereNumber('payrollPeriod')->name('payroll-periods.entries.store');
    Route::delete('payroll-periods/{payrollPeriod}/adjustment-entries/{entry}', [PayrollPeriodController::class, 'removeAdjustmentEntry'])->whereNumber(['payrollPeriod', 'entry'])->name('payroll-periods.entries.destroy');

    Route::get('payroll-runs/{payrollRun}', [PayrollRunController::class, 'show'])->whereNumber('payrollRun')->name('payroll-runs.show');
    Route::get('payroll-runs/{payrollRun}/items', [PayrollRunController::class, 'items'])->whereNumber('payrollRun')->name('payroll-runs.items');
    Route::get('payroll-items/{payrollItem}', [PayrollRunController::class, 'item'])->whereNumber('payrollItem')->name('payroll-items.show');
});
