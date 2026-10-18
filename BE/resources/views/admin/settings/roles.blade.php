@extends('layouts.admin')
@section('title', 'Roles & permissions')

@section('content')
<x-page-header title="Settings" subtitle="What each role may do. Access is always checked by permission, never by role name." :breadcrumbs="[['Settings', route('admin.settings')], ['Roles & permissions']]" />

@include('admin.settings._tabs', ['active' => 'roles'])

<x-alert tone="info" class="mb-6" title="Read-only by design">
    System roles are defined in <code>app/Support/PermissionRegistry.php</code> and re-applied by <code>RolesAndPermissionsSeeder</code> on every deploy,
    so edits made in a browser would be silently undone. To change a role, change the registry and redeploy.
</x-alert>

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($roles as $role)
        <x-card>
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-[15px] font-semibold text-ink">{{ $role->name }}</h2>
                @if ($role->is_system)<x-badge tone="neutral" :dot="false">System</x-badge>@endif
            </div>
            <p class="mt-1 text-[12.5px] text-ink-3">{{ $role->description }}</p>
            <p class="mt-3 text-xs text-ink-2"><span class="font-semibold text-ink">{{ $role->users_count }}</span> {{ \Illuminate\Support\Str::plural('user', $role->users_count) }} · <span class="font-semibold text-ink">{{ count($grants[$role->id]) }}</span> permissions</p>
        </x-card>
    @endforeach
</div>

<div class="card overflow-hidden">
    <div class="relative overflow-x-auto">
        <table class="dt">
            <thead>
                <tr>
                    <th class="min-w-64">Permission</th>
                    @foreach ($roles as $role)<th class="text-center">{{ $role->name }}</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($groups as $group => $permissions)
                    <tr><td colspan="{{ $roles->count() + 1 }}" class="!bg-surface-2 !py-2 text-xs font-semibold uppercase tracking-wide text-ink-3">{{ $group }}</td></tr>
                    @foreach ($permissions as $slug => $label)
                        <tr>
                            <td><span class="block font-medium text-ink">{{ $label }}</span><code class="text-[11px] text-ink-3">{{ $slug }}</code></td>
                            @foreach ($roles as $role)
                                @php $has = in_array($slug, $grants[$role->id], true); @endphp
                                <td class="text-center">
                                    @if ($has)
                                        <span class="inline-grid size-6 place-items-center rounded-full bg-success-soft text-success-ink" title="{{ $role->name }}: allowed"><x-icon name="check" class="size-3.5" stroke="2.6" /><span class="sr-only">Allowed</span></span>
                                    @else
                                        <span class="text-ink-3/60" title="{{ $role->name }}: not allowed">—<span class="sr-only">Not allowed</span></span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
