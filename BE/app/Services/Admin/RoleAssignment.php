<?php

namespace App\Services\Admin;

use App\Models\Role;
use App\Models\User;

/**
 * Privilege-escalation guard: a user may only hand out roles whose permissions they hold themselves.
 * (HR can create Manager/Employee logins, but never an Admin login.)
 */
class RoleAssignment
{
    /** @return array<string, string> role slug => name */
    public function assignableTo(User $actor): array
    {
        $mine = $actor->permissionSlugs();

        return Role::query()->with('permissions:id,slug')->orderBy('id')->get()
            ->filter(fn (Role $role) => $role->permissions->pluck('slug')->diff($mine)->isEmpty())
            ->mapWithKeys(fn (Role $role) => [$role->slug => $role->name])
            ->all();
    }
}
