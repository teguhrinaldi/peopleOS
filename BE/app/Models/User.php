<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'is_active', 'locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    // No Illuminate Notifiable: in-app notifications have their own table and NotificationService.
    use HasApiTokens, HasFactory, SoftDeletes;

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class)->latest('id');
    }

    /** @return list<string> */
    public function permissionSlugs(): array
    {
        return $this->permissionCache ??= Permission::query()
            ->whereIn('id', function ($q) {
                $q->select('role_permissions.permission_id')
                    ->from('role_permissions')
                    ->join('user_roles', 'user_roles.role_id', '=', 'role_permissions.role_id')
                    ->where('user_roles.user_id', $this->getKey());
            })
            ->pluck('slug')
            ->all();
    }

    public function hasPermission(string $slug): bool
    {
        return $this->is_active && in_array($slug, $this->permissionSlugs(), true);
    }

    public function hasAnyPermission(string ...$slugs): bool
    {
        foreach ($slugs as $slug) {
            if ($this->hasPermission($slug)) {
                return true;
            }
        }

        return false;
    }

    public function assignRole(string $slug): void
    {
        $this->roles()->syncWithoutDetaching([Role::query()->where('slug', $slug)->firstOrFail()->id]);
        $this->forgetPermissionCache();
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionCache = null;
    }
}
