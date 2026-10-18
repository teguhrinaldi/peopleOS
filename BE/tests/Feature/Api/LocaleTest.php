<?php

namespace Tests\Feature\Api;

use App\Models\Activity;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkplaceFixtures;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase, WorkplaceFixtures;

    private const PREFS = '/api/v1/auth/preferences';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkplace();
    }

    public function test_preference_is_saved_on_the_user_returned_by_me_and_audited(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);

        $this->putJson(self::PREFS, ['locale' => 'id'])
            ->assertOk()
            ->assertJsonPath('data.preferences.locale', 'id')
            ->assertJsonPath('data.preferences.supported_locales.id', 'Bahasa Indonesia');

        $this->assertSame('id', $user->fresh()->locale);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.preferences.locale', 'id')->assertJsonPath('data.preferences.effective_locale', 'id');
        $this->assertTrue(Activity::query()->where('action', 'user.preferences_updated')->where('actor_id', $user->id)->exists());
    }

    public function test_unsupported_locales_are_rejected_and_null_resets_to_the_default(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);

        $this->putJson(self::PREFS, ['locale' => 'fr'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->putJson(self::PREFS, [])->assertStatus(422);

        $this->putJson(self::PREFS, ['locale' => 'id'])->assertOk();
        $this->putJson(self::PREFS, ['locale' => null])->assertOk()->assertJsonPath('data.preferences.locale', null);
    }

    public function test_the_preference_wins_over_headers_and_the_company_default_applies_last(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);

        // No preference: the request header decides, then the company default.
        $this->withHeaders(['X-Locale' => 'id'])->getJson('/api/v1/auth/me')->assertJsonPath('data.preferences.effective_locale', 'id');
        app(Settings::class)->set(['general.locale' => 'id']);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.preferences.effective_locale', 'id');

        // A saved preference beats the header.
        $user->forceFill(['locale' => 'en'])->save();
        $this->withHeaders(['X-Locale' => 'id'])->getJson('/api/v1/auth/me')->assertJsonPath('data.preferences.effective_locale', 'en');
    }

    public function test_locale_never_changes_stored_money_or_timestamps(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAsApi($user);
        $this->at('2026-09-30 08:00');
        $before = $this->getJson('/api/v1/attendance/today')->json('data.schedule');

        $this->putJson(self::PREFS, ['locale' => 'id'])->assertOk();

        $this->assertSame($before, $this->getJson('/api/v1/attendance/today')->json('data.schedule'));
    }

    public function test_anonymous_callers_cannot_set_preferences(): void
    {
        $this->putJson(self::PREFS, ['locale' => 'id'])->assertStatus(401);
    }
}
