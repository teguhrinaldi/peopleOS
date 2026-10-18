<?php

namespace App\Support;

use App\Models\User;

/**
 * The locked admin information architecture (see the product spec). Single source for the sidebar,
 * the placeholder pages and the route permission map, so a link can never exist without a guard.
 *
 * Each item lists the permissions that reveal it (any-of). `placeholder` items render the shared
 * "arrives in a later release" page until their module is built.
 */
final class AdminNavigation
{
    /** @return list<array<string, mixed>> */
    public static function definition(): array
    {
        return [
            ['type' => 'link', 'key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'route' => 'admin.dashboard', 'match' => 'admin.dashboard', 'permissions' => ['admin.access']],
            ['type' => 'group', 'key' => 'people', 'label' => 'People', 'items' => [
                ['key' => 'employees', 'label' => 'Employees', 'icon' => 'users', 'route' => 'admin.employees.index', 'match' => 'admin.employees.*', 'permissions' => ['employee.view', 'employee.view_team']],
                ['key' => 'departments', 'label' => 'Departments', 'icon' => 'building-2', 'route' => 'admin.departments.index', 'match' => 'admin.departments.*', 'permissions' => ['organization.view']],
                ['key' => 'positions', 'label' => 'Positions', 'icon' => 'briefcase', 'route' => 'admin.positions.index', 'match' => 'admin.positions.*', 'permissions' => ['organization.view']],
                ['key' => 'organization', 'label' => 'Organization', 'icon' => 'network', 'route' => 'admin.organization.show', 'match' => 'admin.organization.*', 'permissions' => ['organization.view']],
            ]],
            ['type' => 'group', 'key' => 'attendance', 'label' => 'Attendance', 'items' => [
                ['key' => 'attendance', 'label' => 'Attendance', 'icon' => 'clock', 'route' => 'admin.attendance', 'match' => 'admin.attendance*', 'permissions' => ['attendance.view', 'attendance.view_team']],
                ['key' => 'shifts', 'label' => 'Shifts', 'icon' => 'calendar-clock', 'route' => 'admin.shifts', 'match' => 'admin.shifts*', 'permissions' => ['attendance.manage']],
                ['key' => 'work-locations', 'label' => 'Work Locations', 'icon' => 'map-pin', 'route' => 'admin.work-locations', 'match' => 'admin.work-locations*', 'permissions' => ['attendance.manage']],
                ['key' => 'overtime', 'label' => 'Overtime', 'icon' => 'timer', 'route' => 'admin.overtime', 'match' => 'admin.overtime*', 'permissions' => ['overtime.view', 'overtime.approve', 'overtime.approve_team']],
            ]],
            ['type' => 'group', 'key' => 'leave', 'label' => 'Leave', 'items' => [
                ['key' => 'leave-requests', 'label' => 'Leave Requests', 'icon' => 'calendar-days', 'route' => 'admin.leave-requests', 'match' => 'admin.leave-requests*', 'permissions' => ['leave.view', 'leave.view_team', 'leave.approve', 'leave.approve_team']],
                ['key' => 'leave-types', 'label' => 'Leave Types', 'icon' => 'clipboard-list', 'route' => 'admin.leave-types', 'match' => 'admin.leave-types*', 'permissions' => ['leave.manage']],
            ]],
            ['type' => 'group', 'key' => 'payroll', 'label' => 'Payroll', 'items' => [
                ['key' => 'payroll', 'label' => 'Payroll', 'icon' => 'wallet', 'route' => 'admin.payroll', 'match' => 'admin.payroll*', 'permissions' => ['payroll.view']],
                ['key' => 'salary-structures', 'label' => 'Salary Structures', 'icon' => 'layers', 'route' => 'admin.salary-structures', 'match' => 'admin.salary-structures*', 'permissions' => ['salary.view']],
                ['key' => 'payslips', 'label' => 'Payslips', 'icon' => 'receipt', 'route' => 'admin.payslips', 'match' => 'admin.payslips*', 'permissions' => ['payslip.view']],
            ]],
            ['type' => 'link', 'key' => 'documents', 'label' => 'Documents', 'icon' => 'folder', 'route' => 'admin.documents', 'match' => 'admin.documents*', 'permissions' => ['document.view']],
            ['type' => 'link', 'key' => 'reports', 'label' => 'Reports', 'icon' => 'chart-column', 'route' => 'admin.reports', 'match' => 'admin.reports*', 'permissions' => ['report.view']],
            // The audit log lives inside Settings (locked IA: no top-level Activity item). People who may only read
            // the audit log (HR) land on it directly; `routes` picks the first page the user holds a permission for.
            ['type' => 'link', 'key' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'route' => 'admin.settings', 'match' => ['admin.settings*', 'admin.activity*'],
                'permissions' => ['settings.manage', 'activity.view'], 'routes' => ['settings.manage' => 'admin.settings', 'activity.view' => 'admin.activity']],
        ];
    }

    /**
     * Sidebar tree limited to what the user may open, with `active` flags for the current request.
     *
     * @return list<array<string, mixed>>
     */
    public static function forUser(User $user): array
    {
        $tree = [];

        foreach (self::definition() as $entry) {
            if ($entry['type'] === 'link') {
                if ($user->hasAnyPermission(...$entry['permissions'])) {
                    $entry['active'] = request()->routeIs(...(array) $entry['match']);
                    $entry['route'] = self::routeFor($user, $entry);
                    $tree[] = $entry;
                }

                continue;
            }

            $items = [];
            foreach ($entry['items'] as $item) {
                if ($user->hasAnyPermission(...$item['permissions'])) {
                    $item['active'] = request()->routeIs(...(array) $item['match']);
                    $items[] = $item;
                }
            }

            if ($items !== []) {
                $entry['items'] = $items;
                $entry['active'] = in_array(true, array_column($items, 'active'), true);
                $tree[] = $entry;
            }
        }

        return $tree;
    }

    /** @param array<string, mixed> $entry */
    private static function routeFor(User $user, array $entry): string
    {
        foreach ($entry['routes'] ?? [] as $permission => $route) {
            if ($user->hasPermission($permission)) {
                return $route;
            }
        }

        return $entry['route'];
    }

    /** @return array<string, mixed>|null the flat item (link or group child) for a key */
    public static function find(string $key): ?array
    {
        foreach (self::definition() as $entry) {
            if ($entry['type'] === 'link' && $entry['key'] === $key) {
                return $entry;
            }
            foreach ($entry['items'] ?? [] as $item) {
                if ($item['key'] === $key) {
                    return $item + ['group' => $entry['label']];
                }
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> every placeholder item (used to register routes) */
    public static function placeholders(): array
    {
        $out = [];
        foreach (self::definition() as $entry) {
            foreach ($entry['type'] === 'link' ? [$entry] : $entry['items'] as $item) {
                if (isset($item['placeholder'])) {
                    $out[] = $item;
                }
            }
        }

        return $out;
    }
}
