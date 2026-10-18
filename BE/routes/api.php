<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CorrectionController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OvertimeController;
use Illuminate\Support\Facades\Route;

/*
| PeopleOS REST API v1  (base path: /api/v1)
| Sanctum bearer tokens. Every protected route additionally passes the `active` middleware
| (disabled accounts and terminated employees are refused) and per-route policies.
*/
Route::prefix('v1')->as('api.v1.')->group(function () {
    Route::get('ping', fn () => response()->json(['success' => true, 'data' => ['status' => 'ok', 'version' => 'v1']]))->name('ping');

    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:api-login')->name('auth.login');

    Route::middleware(['auth:sanctum', 'active', 'locale', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::put('auth/preferences', [AuthController::class, 'preferences'])->name('auth.preferences');

        // Feature modules keep their routes in routes/api/v1/<module>.php (same middleware, same prefix).
        foreach (glob(__DIR__.'/api/v1/*.php') as $moduleRoutes) {
            require $moduleRoutes;
        }

        // Organization reference data
        Route::get('organization/company', [OrganizationController::class, 'company']);
        Route::get('organization/branches', [OrganizationController::class, 'branches']);
        Route::get('organization/departments', [OrganizationController::class, 'departments']);
        Route::get('organization/positions', [OrganizationController::class, 'positions']);
        Route::get('organization/work-locations', [OrganizationController::class, 'workLocations']);
        Route::get('shifts', [OrganizationController::class, 'shifts']);

        // Employees (read-only)
        Route::get('employees/me', [EmployeeController::class, 'me'])->name('employees.me');
        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->whereNumber('employee')->name('employees.show');

        // Attendance
        Route::get('attendance/today', [AttendanceController::class, 'today'])->name('attendance.today');
        Route::post('attendance/check-in', [AttendanceController::class, 'checkIn'])->middleware('throttle:attendance')->name('attendance.check-in');
        Route::post('attendance/check-out', [AttendanceController::class, 'checkOut'])->middleware('throttle:attendance')->name('attendance.check-out');
        Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
        Route::get('attendance/{attendance}', [AttendanceController::class, 'show'])->whereNumber('attendance')->name('attendance.show');
        Route::post('attendance/{attendance}/approve', [AttendanceController::class, 'approve'])->whereNumber('attendance')->name('attendance.approve');
        Route::post('attendance/{attendance}/reject', [AttendanceController::class, 'reject'])->whereNumber('attendance')->name('attendance.reject');
        Route::get('attendance-events/{event}/selfie', [AttendanceController::class, 'selfie'])->whereNumber('event')->name('attendance.selfie');

        // Attendance corrections
        Route::get('attendance-corrections', [CorrectionController::class, 'index'])->name('corrections.index');
        Route::post('attendance-corrections', [CorrectionController::class, 'store'])->name('corrections.store');
        Route::get('attendance-corrections/{correction}', [CorrectionController::class, 'show'])->whereNumber('correction')->name('corrections.show');
        Route::post('attendance-corrections/{correction}/approve', [CorrectionController::class, 'approve'])->whereNumber('correction')->name('corrections.approve');
        Route::post('attendance-corrections/{correction}/reject', [CorrectionController::class, 'reject'])->whereNumber('correction')->name('corrections.reject');
        Route::post('attendance-corrections/{correction}/cancel', [CorrectionController::class, 'cancel'])->whereNumber('correction')->name('corrections.cancel');

        // Overtime
        Route::get('overtime-requests', [OvertimeController::class, 'index'])->name('overtime.index');
        Route::post('overtime-requests', [OvertimeController::class, 'store'])->name('overtime.store');
        Route::get('overtime-requests/{overtimeRequest}', [OvertimeController::class, 'show'])->whereNumber('overtimeRequest')->name('overtime.show');
        Route::post('overtime-requests/{overtimeRequest}/approve', [OvertimeController::class, 'approve'])->whereNumber('overtimeRequest')->name('overtime.approve');
        Route::post('overtime-requests/{overtimeRequest}/reject', [OvertimeController::class, 'reject'])->whereNumber('overtimeRequest')->name('overtime.reject');
        Route::post('overtime-requests/{overtimeRequest}/cancel', [OvertimeController::class, 'cancel'])->whereNumber('overtimeRequest')->name('overtime.cancel');
    });
});
