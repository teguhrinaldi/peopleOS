<?php

use App\Http\Controllers\Api\V1\ExpenseSetupController;
use App\Http\Controllers\Api\V1\ReimbursementController;
use Illuminate\Support\Facades\Route;

// Included inside the authenticated /api/v1 group in routes/api.php (auth:sanctum, active, throttle:api).
// Ids are plain integers resolved inside the caller's visible set (no implicit model binding): a foreign or unknown id is a 404.

Route::get('expense-categories', [ExpenseSetupController::class, 'categories'])->name('expense-categories.index');
Route::post('expense-categories', [ExpenseSetupController::class, 'storeCategory'])->name('expense-categories.store');
Route::patch('expense-categories/{id}', [ExpenseSetupController::class, 'updateCategory'])->whereNumber('id')->name('expense-categories.update');
Route::get('expense-policy', [ExpenseSetupController::class, 'policy'])->name('expense-policy.show');
Route::put('expense-policy', [ExpenseSetupController::class, 'updatePolicy'])->name('expense-policy.update');

Route::get('reimbursements', [ReimbursementController::class, 'index'])->name('reimbursements.index');
Route::post('reimbursements', [ReimbursementController::class, 'store'])->name('reimbursements.store');
Route::get('reimbursements/{id}', [ReimbursementController::class, 'show'])->whereNumber('id')->name('reimbursements.show');
Route::patch('reimbursements/{id}', [ReimbursementController::class, 'update'])->whereNumber('id')->name('reimbursements.update');
Route::post('reimbursements/{id}/receipt', [ReimbursementController::class, 'uploadReceipt'])->whereNumber('id')->name('reimbursements.receipt.upload');
Route::get('reimbursements/{id}/receipt', [ReimbursementController::class, 'receipt'])->whereNumber('id')->name('reimbursements.receipt.download');
Route::get('reimbursements/{id}/history', [ReimbursementController::class, 'history'])->whereNumber('id')->name('reimbursements.history');
Route::post('reimbursements/{id}/submit', [ReimbursementController::class, 'submit'])->whereNumber('id')->name('reimbursements.submit');
Route::post('reimbursements/{id}/review', [ReimbursementController::class, 'review'])->whereNumber('id')->name('reimbursements.review');
Route::post('reimbursements/{id}/approve', [ReimbursementController::class, 'approve'])->whereNumber('id')->name('reimbursements.approve');
Route::post('reimbursements/{id}/reject', [ReimbursementController::class, 'reject'])->whereNumber('id')->name('reimbursements.reject');
Route::post('reimbursements/{id}/withdraw', [ReimbursementController::class, 'withdraw'])->whereNumber('id')->name('reimbursements.withdraw');
Route::post('reimbursements/{id}/cancel', [ReimbursementController::class, 'cancel'])->whereNumber('id')->name('reimbursements.cancel');
Route::post('reimbursements/{id}/schedule', [ReimbursementController::class, 'schedule'])->whereNumber('id')->name('reimbursements.schedule');
Route::post('reimbursements/{id}/pay', [ReimbursementController::class, 'pay'])->whereNumber('id')->name('reimbursements.pay');
