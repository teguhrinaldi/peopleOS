<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EmployeeStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\EmployeeResource;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\CompanyContext;
use App\Support\Locales;
use App\Support\Settings;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends ApiController
{
    public function __construct(private readonly CompanyContext $context, private readonly ActivityLogger $activity) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $throttleKey = 'login:'.strtolower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ApiException::make('TOO_MANY_ATTEMPTS', 'Too many sign-in attempts. Try again later.', 429, [
                'retry_after_seconds' => RateLimiter::availableIn($throttleKey),
            ]);
        }

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);
            throw ApiException::make('INVALID_CREDENTIALS', 'The email or password is incorrect.', 401);
        }

        if (! $user->is_active) {
            throw ApiException::make('ACCOUNT_DISABLED', 'This account has been disabled.', 403);
        }

        $employee = $user->employee;
        if (! $employee) {
            throw ApiException::make('NO_EMPLOYEE_PROFILE', 'This account is not linked to an employee profile.', 403);
        }
        if ($employee->status === EmployeeStatus::Terminated) {
            throw ApiException::make('EMPLOYEE_TERMINATED', 'Your employment has ended. Contact HR.', 403);
        }
        if (! $user->hasPermission('attendance.self')) {
            throw ApiException::make('FORBIDDEN', 'This account cannot use the employee app.', 403);
        }

        RateLimiter::clear($throttleKey);

        $minutes = (int) config('peopleos.api.token_expiration_minutes');
        $token = $user->createToken($data['device_name'] ?? 'mobile', ['*'], now()->addMinutes($minutes));
        $user->forceFill(['last_login_at' => now()])->save();

        $this->activity->log('auth.login', $user, actor: $user);

        return $this->ok(array_merge($this->profile($user), [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ]));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        $this->activity->log('auth.logout', $request->user(), actor: $request->user());

        return $this->ok(null);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok($this->profile($request->user()));
    }

    /** Language preference (stored on the user so it follows them across devices). `null` = follow the company default. */
    public function preferences(Request $request): JsonResponse
    {
        $data = $request->validate(['locale' => ['present', 'nullable', 'string', Rule::in(array_keys(Locales::supported()))]]);

        $user = $request->user();
        $before = $user->locale;
        $user->forceFill(['locale' => $data['locale']])->save();
        $this->activity->log('user.preferences_updated', $user, ['locale' => $before], ['locale' => $data['locale']], actor: $user);

        return $this->ok($this->profile($user));
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        $employee = $user->employee?->load(['currentAssignment.department', 'currentAssignment.position', 'currentAssignment.branch', 'currentAssignment.manager', 'currentAssignment.workLocation', 'currentAssignment.shift']);
        $company = $this->context->company();

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'employee' => $employee ? (new EmployeeResource($employee))->resolve() : null,
            'permissions' => $user->permissionSlugs(),
            'company' => ['id' => $company->id, 'name' => $company->name, 'timezone' => $company->timezone],
            'preferences' => [
                'locale' => $user->locale,                              // the user's own choice, or null
                'effective_locale' => app()->getLocale(),               // what this request is actually served in
                'default_locale' => app(Settings::class)->string('general.locale'),
                'supported_locales' => Locales::supported(),
                'currency' => app(Settings::class)->string('general.currency'),
            ],
        ];
    }
}
