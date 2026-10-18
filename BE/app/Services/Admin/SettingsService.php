<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\AttendancePolicy;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\CompanyContext;
use App\Support\DateDisplay;
use App\Support\PermissionRegistry;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * General company settings and the role/permission overview.
 *
 * Roles are shown read-only on purpose: the system roles are defined in PermissionRegistry and
 * RolesAndPermissionsSeeder re-applies them on every deploy, so an edit made here would silently
 * be undone. Changing a role means changing the registry (reviewed in code).
 */
class SettingsService
{
    private const DISK = 'public';

    public function __construct(
        private readonly CompanyContext $context,
        private readonly OrganizationService $organization,
        private readonly Settings $settings,
        private readonly ActivityLogger $activity,
        private readonly LocationService $locations,
    ) {}

    /**
     * Typed settings (AppSupportSettings). Only changed keys are written and audited as one
     * "settings.updated" row with the old and new values.
     *
     * @param  array<string, mixed>  $values  setting key => value
     */
    public function updateSettings(array $values, User $actor): void
    {
        $old = $this->settings->effective();
        $changed = [];
        foreach ($values as $key => $value) {
            if ((string) $this->normalize($old[$key] ?? null) !== (string) $this->normalize($value)) {
                $changed[$key] = $value;
            }
        }

        if ($changed === []) {
            return;
        }

        DB::transaction(function () use ($changed, $old, $actor) {
            $this->settings->set($changed);
            $this->activity->log('settings.updated', $this->context->company(), array_intersect_key($old, $changed), $changed, actor: $actor);
        });
        DateDisplay::forget();
    }

    /** The default attendance policy decides the selfie rule for every location without its own policy. */
    public function setDefaultSelfieRequired(bool $required, User $actor): AttendancePolicy
    {
        $policy = AttendancePolicy::query()->where('company_id', $this->context->id())->where('is_default', true)->first()
            ?? throw ApiException::make('POLICY_MISSING', 'There is no default attendance policy yet. Create one under Work Locations → Attendance policies.', 409);

        return $this->locations->savePolicy($policy, ['require_selfie' => $required] + $policy->only([
            'name', 'is_default', 'require_gps', 'geofence_mode', 'max_gps_accuracy_meters', 'reject_mock_location', 'offline_max_age_minutes',
        ]), $actor);
    }

    private function normalize(mixed $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    /** @param array<string, mixed> $data name, timezone, address */
    public function updateGeneral(array $data, ?UploadedFile $logo, bool $removeLogo, User $actor): Company
    {
        $company = $this->context->company();
        $old = $company->logo_path;
        $new = $logo ? (string) $logo->store('company', self::DISK) : null;

        if ($new) {
            $data['logo_path'] = $new;
        } elseif ($removeLogo) {
            $data['logo_path'] = null;
        }

        try {
            $company = $this->organization->updateCompany($data, $actor);
        } catch (Throwable $e) {
            $new && Storage::disk(self::DISK)->delete($new);
            throw $e;
        }

        if ($old && $old !== $company->logo_path) {
            Storage::disk(self::DISK)->delete($old);
        }

        return $company;
    }

    /**
     * @return array{roles: Collection<int, Role>, groups: array<string, array<string, string>>, grants: array<int, list<string>>}
     */
    public function roleMatrix(): array
    {
        $roles = Role::query()->with('permissions:id,slug')->withCount('users')->orderBy('id')->get();

        return [
            'roles' => $roles,
            'groups' => PermissionRegistry::all(),
            'grants' => $roles->mapWithKeys(fn (Role $r) => [$r->id => $r->permissions->pluck('slug')->all()])->all(),
        ];
    }
}
