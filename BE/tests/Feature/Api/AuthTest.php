<?php

namespace Tests\Feature\Api;

use App\Enums\EmployeeStatus;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase, WorkplaceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkplace();
    }

    private function login(string $email, string $password = 'password')
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password, 'device_name' => 'phpunit']);
    }

    public function test_login_returns_token_profile_permissions_and_company(): void
    {
        [$user, $employee] = $this->makeStaff();

        $this->login($user->email)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.employee.employee_number', $employee->employee_number)
            ->assertJsonPath('data.employee.department.name', 'Marketing')
            ->assertJsonPath('data.company.timezone', 'Asia/Jakarta')
            ->assertJsonFragment(['attendance.self'])
            ->assertJsonMissing(['employee.create']);

        $this->assertNotEmpty($this->login($user->email)->json('data.token'));
    }

    public function test_wrong_credentials_use_a_stable_error_code_and_no_hint_which_part_was_wrong(): void
    {
        [$user] = $this->makeStaff();

        $wrongPassword = $this->login($user->email, 'nope')->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $unknownEmail = $this->login('ghost@test.example')->assertStatus(401);

        $this->assertSame($wrongPassword->json('error.message'), $unknownEmail->json('error.message'));
    }

    public function test_repeated_failures_are_throttled(): void
    {
        [$user] = $this->makeStaff();

        foreach (range(1, 5) as $_) {
            $this->login($user->email, 'bad')->assertStatus(401);
        }

        $this->login($user->email, 'bad')->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');
        $this->login($user->email)->assertStatus(429); // even the right password waits
    }

    public function test_disabled_and_terminated_accounts_cannot_sign_in(): void
    {
        [$disabled] = $this->makeStaff();
        $disabled->update(['is_active' => false]);
        $this->login($disabled->email)->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_DISABLED');

        [$gone, $employee] = $this->makeStaff();
        $employee->update(['status' => EmployeeStatus::Terminated->value]);
        $this->login($gone->email)->assertForbidden()->assertJsonPath('error.code', 'EMPLOYEE_TERMINATED');
    }

    public function test_account_without_employee_profile_cannot_use_the_employee_api(): void
    {
        $user = User::query()->create(['name' => 'Ghost', 'email' => 'ghost@test.example', 'password' => 'password', 'is_active' => true]);
        $user->assignRole('admin');

        $this->login($user->email)->assertForbidden()->assertJsonPath('error.code', 'NO_EMPLOYEE_PROFILE');
    }

    public function test_protected_routes_require_a_token_and_return_the_json_error_envelope(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->getJson('/api/v1/attendance/today')->assertStatus(401);
        $this->getJson('/api/v1/nothing-here')->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_me_and_logout_work_with_a_real_token_and_logout_revokes_it(): void
    {
        [$user] = $this->makeStaff();
        $token = $this->login($user->email)->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.email', $user->email);
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_a_disabled_user_with_a_valid_token_is_shut_out_immediately(): void
    {
        [$user] = $this->makeStaff();
        $token = $this->login($user->email)->json('data.token');
        $user->update(['is_active' => false]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_DISABLED');
    }

    public function test_login_is_audited_and_never_stores_the_token_or_password(): void
    {
        [$user] = $this->makeStaff();
        $this->login($user->email)->assertOk();

        $activity = Activity::query()->where('action', 'auth.login')->firstOrFail();
        $this->assertSame($user->id, $activity->actor_id);
        $this->assertStringNotContainsString('password', strtolower(json_encode($activity->getAttributes())));
    }

    public function test_validation_errors_carry_field_details(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['email', 'password']]]);
    }
}
