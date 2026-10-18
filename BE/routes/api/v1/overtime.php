<?php

use App\Http\Controllers\Api\V1\Overtime\OvertimeRuleController;
use App\Http\Controllers\Api\V1\OvertimeController;
use Illuminate\Support\Facades\Route;

// Included inside the authenticated /api/v1 group in routes/api.php. Overtime REQUEST routes live in routes/api.php.

Route::middleware('permission:overtime.manage,payroll.manage,payroll.view')->group(function () {
    Route::get('overtime-rules', [OvertimeRuleController::class, 'index'])->name('overtime-rules.index');
    Route::get('overtime-rules/{overtimeRule}', [OvertimeRuleController::class, 'show'])->whereNumber('overtimeRule')->name('overtime-rules.show');
});
Route::middleware('permission:overtime.manage,payroll.manage')->group(function () {
    Route::post('overtime-rules', [OvertimeRuleController::class, 'store'])->name('overtime-rules.store');
    Route::patch('overtime-rules/{overtimeRule}', [OvertimeRuleController::class, 'update'])->whereNumber('overtimeRule')->name('overtime-rules.update');
    Route::delete('overtime-rules/{overtimeRule}', [OvertimeRuleController::class, 'destroy'])->whereNumber('overtimeRule')->name('overtime-rules.destroy');
});

// Controlled correction of a FINALIZED inclusion (the service re-checks payroll.manage).
Route::post('overtime-requests/{overtimeRequest}/void-payroll-inclusion', [OvertimeController::class, 'voidInclusion'])
    ->middleware('permission:payroll.manage')->whereNumber('overtimeRequest')->name('overtime.void-inclusion');
