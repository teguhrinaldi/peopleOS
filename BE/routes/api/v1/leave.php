<?php

use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\LeaveSetupController;
use Illuminate\Support\Facades\Route;

// Included inside the authenticated /api/v1 group in routes/api.php.
Route::get('leave-types', [LeaveController::class, 'types'])->name('leave-types.index');
Route::get('leave-balances', [LeaveController::class, 'balances'])->name('leave-balances.index');

Route::get('leave-requests', [LeaveController::class, 'index'])->name('leave-requests.index');
Route::post('leave-requests/preview', [LeaveController::class, 'preview'])->name('leave-requests.preview');
Route::post('leave-requests', [LeaveController::class, 'store'])->name('leave-requests.store');
Route::get('leave-requests/{leaveRequest}', [LeaveController::class, 'show'])->whereNumber('leaveRequest')->name('leave-requests.show');
Route::patch('leave-requests/{leaveRequest}', [LeaveController::class, 'update'])->whereNumber('leaveRequest')->name('leave-requests.update');
Route::post('leave-requests/{leaveRequest}/submit', [LeaveController::class, 'submit'])->whereNumber('leaveRequest')->name('leave-requests.submit');
Route::post('leave-requests/{leaveRequest}/approve', [LeaveController::class, 'approve'])->whereNumber('leaveRequest')->name('leave-requests.approve');
Route::post('leave-requests/{leaveRequest}/reject', [LeaveController::class, 'reject'])->whereNumber('leaveRequest')->name('leave-requests.reject');
Route::post('leave-requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->whereNumber('leaveRequest')->name('leave-requests.cancel');

Route::middleware('permission:leave.manage')->prefix('leave-setup')->name('leave-setup.')->group(function () {
    Route::get('types', [LeaveSetupController::class, 'index'])->name('types.index');
    Route::post('types', [LeaveSetupController::class, 'store'])->name('types.store');
    Route::patch('types/{leaveType}', [LeaveSetupController::class, 'update'])->whereNumber('leaveType')->name('types.update');
    Route::put('types/{leaveType}/policy', [LeaveSetupController::class, 'savePolicy'])->whereNumber('leaveType')->name('types.policy');
    Route::post('balances/{leaveBalance}/adjust', [LeaveSetupController::class, 'adjustBalance'])->whereNumber('leaveBalance')->name('balances.adjust');
});
