<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\Shift;
use App\Services\ActivityLogger;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ActivityAndSettingsTest extends AdminTestCase
{
    // ---- Activity -------------------------------------------------------------------------------

    public function test_the_audit_log_filters_by_actor_action_entity_and_local_date(): void
    {
        [$admin] = $this->signIn('admin');
        [$hr] = $this->makeStaff('hr');
        $employee = $this->person('Subject Sue');
        $logger = app(ActivityLogger::class);

        $logger->log('employee.updated', $employee, ['full_name' => 'Old Sue'], ['full_name' => 'Subject Sue'], actor: $hr);
        $logger->log('shift.updated', $this->shift, ['grace_late_minutes' => 5], ['grace_late_minutes' => 10], actor: $admin);
        // 23:30 Jakarta on the 28th is 16:30 UTC: it belongs to the 28th locally.
        $late = $logger->log('employee.status_changed', $employee, ['status' => 'ACTIVE'], ['status' => 'INACTIVE'], actor: $hr);
        $late->forceFill(['created_at' => CarbonImmutable::parse('2026-09-28 23:30', 'Asia/Jakarta')->utc()])->save();

        $ids = fn (string $qs) => $this->get('/admin/activity'.$qs)->assertOk()->viewData('activities')->getCollection()->pluck('id')->all();

        $this->assertNotContains($late->id, $ids('?from=2026-09-29'));
        $this->assertContains($late->id, $ids('?from=2026-09-28&to=2026-09-28'));
        $this->assertSame(2, count($ids("?actor_id={$hr->id}")));
        $this->assertSame(1, count($ids('?action=shift.updated')));
        $this->assertSame(1, count($ids('?entity_type='.urlencode(Shift::class))));
        $this->get('/admin/activity?from=2026-09-30&to=2026-09-01')->assertSessionHasErrors('to');
    }

    public function test_the_diff_drawer_gets_old_and_new_values_already_redacted(): void
    {
        $this->signIn('admin');
        app(ActivityLogger::class)->log('employee.updated', $this->person('Diff Dan'), ['full_name' => 'Dan', 'password' => 'hunter2'], ['full_name' => 'Diff Dan', 'password' => 'hunter3']);

        $row = $this->get('/admin/activity?action=employee.updated')->assertOk()->viewData('activities')->first();
        $diff = collect($row['diff'])->keyBy('key');

        $this->assertSame(['old' => 'Dan', 'new' => 'Diff Dan', 'changed' => true], collect($diff['Full Name'])->only(['old', 'new', 'changed'])->all());
        $this->assertSame('[redacted]', $diff['Password']['old']);
        $this->get('/admin/activity')->assertDontSee('hunter2')->assertDontSee('hunter3');
    }

    public function test_the_audit_log_paginates(): void
    {
        $this->signIn('admin');
        $logger = app(ActivityLogger::class);
        foreach (range(1, 30) as $i) {
            $logger->log('shift.updated', $this->shift, ['grace_late_minutes' => $i], ['grace_late_minutes' => $i + 1]);
        }

        $page = $this->get('/admin/activity?action=shift.updated')->assertOk()->viewData('activities');
        $this->assertCount(25, $page->items());
        $this->assertSame(30, $page->total());
        $this->assertCount(5, $this->get('/admin/activity?action=shift.updated&page=2')->viewData('activities')->items());
        $this->assertSame(30, Activity::query()->where('action', 'shift.updated')->count(), 'browsing never writes to the trail');
    }

    // ---- Settings -------------------------------------------------------------------------------

    public function test_changing_the_timezone_moves_company_time_immediately(): void
    {
        [$admin] = $this->signIn('admin');
        // 10:00 on 30 Sep in Jakarta is 03:00 UTC, which is still 29 Sep in Los Angeles.
        $this->assertSame('2026-09-30', app(CompanyContext::class)->today()->toDateString());

        $this->put('/admin/settings', ['name' => 'Test Co', 'timezone' => 'America/Los_Angeles'])->assertRedirect('/admin/settings')->assertSessionHas('success');

        $this->assertSame('America/Los_Angeles', $this->company->fresh()->timezone);
        $this->assertSame('America/Los_Angeles', app(CompanyContext::class)->timezone());
        $this->assertSame('2026-09-29', app(CompanyContext::class)->today()->toDateString());
        $this->get('/admin')->assertOk()->assertSee('Tue, 29 Sep 2026');

        $log = Activity::query()->where('action', 'company.updated')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame(['timezone' => 'Asia/Jakarta'], $log->old_values);
        $this->assertSame(['timezone' => 'America/Los_Angeles'], $log->new_values);
    }

    public function test_settings_validate_the_timezone_and_name(): void
    {
        $this->signIn('admin');

        $this->put('/admin/settings', ['name' => '', 'timezone' => 'Mars/Olympus'])->assertSessionHasErrors(['name', 'timezone']);
        $this->assertSame('Asia/Jakarta', $this->company->fresh()->timezone);
    }

    public function test_the_logo_is_stored_on_the_public_disk_replaced_and_removable(): void
    {
        Storage::fake('public');
        $this->signIn('admin');

        $this->put('/admin/settings', ['name' => 'Test Co', 'timezone' => 'Asia/Jakarta', 'logo' => UploadedFile::fake()->image('../logo.png', 300, 100)])->assertSessionHasNoErrors();
        $first = $this->company->fresh()->logo_path;
        $this->assertStringStartsWith('company/', $first);
        Storage::disk('public')->assertExists($first);

        $this->put('/admin/settings', ['name' => 'Test Co', 'timezone' => 'Asia/Jakarta', 'logo' => UploadedFile::fake()->image('new.webp', 200, 200)])->assertSessionHasNoErrors();
        $second = $this->company->fresh()->logo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);

        $this->put('/admin/settings', ['name' => 'Test Co', 'timezone' => 'Asia/Jakarta', 'remove_logo' => 1])->assertSessionHasNoErrors();
        $this->assertNull($this->company->fresh()->logo_path);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_svg_and_oversized_logos_are_refused(): void
    {
        Storage::fake('public');
        $this->signIn('admin');

        $this->put('/admin/settings', ['name' => 'Test Co', 'timezone' => 'Asia/Jakarta', 'logo' => UploadedFile::fake()->create('logo.svg', 2, 'image/svg+xml')])->assertSessionHasErrors('logo');
        $this->put('/admin/settings', ['name' => 'Test Co', 'timezone' => 'Asia/Jakarta', 'logo' => UploadedFile::fake()->image('big.png')->size(2048)])->assertSessionHasErrors('logo');
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_role_matrix_is_read_only_and_reflects_the_registry(): void
    {
        $this->signIn('admin');

        $response = $this->get('/admin/settings/roles')->assertOk()->assertSee('Read-only by design')->assertSee('settings.manage')->assertDontSee('name="permissions', false);
        $grants = $response->viewData('grants');
        $roles = $response->viewData('roles')->keyBy('slug');

        $this->assertContains('settings.manage', $grants[$roles['admin']->id]);
        $this->assertNotContains('settings.manage', $grants[$roles['hr']->id]);
        $this->assertNotContains('employee.view', $grants[$roles['manager']->id]);
        $this->put('/admin/settings/roles', [])->assertStatus(405);
    }
}
