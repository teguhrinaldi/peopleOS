<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionRegistry;
use Illuminate\Database\Seeder;

/** Idempotent: safe to run on every deploy. Only touches the system roles it owns. */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissionIds = [];
        foreach (PermissionRegistry::all() as $group => $permissions) {
            foreach ($permissions as $slug => $label) {
                $permissionIds[$slug] = Permission::query()->updateOrCreate(
                    ['slug' => $slug],
                    ['name' => $label, 'group' => $group],
                )->id;
            }
        }

        // Permissions removed from the registry disappear from every role (role_permissions cascades).
        Permission::query()->whereNotIn('slug', array_keys($permissionIds))->delete();

        foreach (PermissionRegistry::roles() as $slug => $definition) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $definition['name'], 'description' => $definition['description'], 'is_system' => true],
            );

            $role->permissions()->sync(array_map(fn (string $p) => $permissionIds[$p], $definition['permissions']));
        }
    }
}
