<?php

namespace App\Providers;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Policies\AttendanceCorrectionPolicy;
use App\Policies\AttendanceRecordPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\OvertimeRequestPolicy;
use App\Support\CompanyContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CompanyContext::class);
    }

    public function boot(): void
    {
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(Attendance::class, AttendanceRecordPolicy::class);
        Gate::policy(AttendanceCorrection::class, AttendanceCorrectionPolicy::class);
        Gate::policy(OvertimeRequest::class, OvertimeRequestPolicy::class);

        // Web admin: permission slugs are also usable as Gate abilities (@can('employee.create')).
        Gate::before(fn ($user, string $ability) => str_contains($ability, '.') && ! str_contains($ability, '\\')
            ? ($user->hasPermission($ability) ?: null)
            : null);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('api-login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('attendance', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
    }
}
