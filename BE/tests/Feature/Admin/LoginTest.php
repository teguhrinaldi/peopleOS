<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use Illuminate\Support\Facades\RateLimiter;

class LoginTest extends AdminTestCase
{
    public function test_login_page_renders_for_guests_and_root_redirects_to_admin(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in to your account');
        $this->get('/')->assertRedirect('/admin');
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_admin_signs_in_and_lands_on_the_dashboard(): void
    {
        [$user] = $this->makeStaff('admin');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertTrue(Activity::query()->where('action', 'auth.login')->where('actor_id', $user->id)->exists());
        $this->get('/admin')->assertOk()->assertSee('Good morning');
    }

    public function test_a_user_without_admin_access_is_refused_with_the_generic_message(): void
    {
        [$user] = $this->makeStaff('employee');

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'These credentials do not match an account with admin access.']);

        $this->assertGuest();
    }

    public function test_disabled_wrong_password_and_unknown_accounts_all_get_the_same_message(): void
    {
        [$disabled] = $this->makeStaff('admin');
        $disabled->forceFill(['is_active' => false])->save();
        [$active] = $this->makeStaff('admin');

        $message = 'These credentials do not match an account with admin access.';

        foreach ([
            ['email' => $disabled->email, 'password' => 'password'],
            ['email' => $active->email, 'password' => 'wrong-password'],
            ['email' => 'nobody@test.example', 'password' => 'password'],
        ] as $credentials) {
            $this->post('/login', $credentials)->assertSessionHasErrors(['email' => $message]);
            $this->assertGuest();
        }
    }

    public function test_repeated_failures_are_throttled(): void
    {
        [$user] = $this->makeStaff('admin');
        RateLimiter::clear(strtolower($user->email).'|127.0.0.1');

        foreach (range(1, 5) as $ignored) {
            $this->post('/login', ['email' => $user->email, 'password' => 'nope']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertStringContainsString('Too many sign-in attempts', session('errors')->first('email'));
    }

    public function test_logout_is_a_post_and_ends_the_session(): void
    {
        [$user] = $this->signIn('admin');

        $this->get('/logout')->assertStatus(405);
        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertTrue(Activity::query()->where('action', 'auth.logout')->where('actor_id', $user->id)->exists());
    }

    public function test_an_account_disabled_mid_session_is_signed_out(): void
    {
        [$user] = $this->signIn('admin');
        $user->forceFill(['is_active' => false])->save();

        $this->get('/admin')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_an_employee_only_user_with_a_session_gets_403_on_the_admin(): void
    {
        $this->signIn('employee');

        $this->get('/admin')->assertForbidden();
        $this->get('/admin/employees')->assertForbidden();
    }
}
