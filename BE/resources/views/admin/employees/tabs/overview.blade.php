@php
    use Illuminate\Support\Facades\Blade;
    use Illuminate\Support\HtmlString;
    use Illuminate\Support\Str;

    $age = $employee->date_of_birth?->age;
    $manager = $placement?->manager;
    $managerCell = null;
    if ($manager) {
        $managerCell = Gate::allows('view', $manager)
            ? new HtmlString('<a class="text-primary-ink hover:underline" href="'.e(route('admin.employees.show', $manager)).'">'.e($manager->full_name).'</a>')
            : $manager->full_name;
    }
    $shift = $placement?->shift;
    $statusCell = new HtmlString(Blade::render('<x-badge :status="$status" />', ['status' => $employee->status]));
    $context = app(\App\Support\CompanyContext::class);
@endphp
<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    <x-card title="Personal information">
        <x-kv :items="[
            'Full name' => $employee->full_name,
            'Gender' => $employee->gender ? ucfirst($employee->gender->value) : null,
            'Date of birth' => $employee->date_of_birth ? $employee->date_of_birth->format('d M Y').' ('.$age.' years)' : null,
            'Phone' => $employee->phone,
            'Email' => $employee->email,
            'Address' => $employee->address,
        ]" />
    </x-card>

    <x-card title="Employment">
        <x-kv :items="[
            'Department' => $placement?->department?->name,
            'Position' => $placement?->position?->name,
            'Manager' => $managerCell,
            'Branch' => $placement?->branch?->name,
            'Work location' => $placement?->workLocation?->name,
            'Shift' => $shift ? $shift->name.' ('.$shift->startLabel().'–'.$shift->endLabel().')' : null,
            'Join date' => $employee->join_date->format('d M Y'),
            'Employment type' => Str::headline($employee->employment_type->value),
            'Status' => $statusCell,
        ]" />
    </x-card>

    @can('employee.edit')
        <x-card title="Account" class="lg:col-span-2">
            @if ($employee->user)
                <x-kv cols="2" :items="[
                    'Login email' => $employee->user->email,
                    'Role' => $employee->user->roles->pluck('name')->join(', '),
                    'Login' => $employee->user->is_active ? 'Enabled' : 'Disabled',
                    'Last sign-in' => $employee->user->last_login_at ? $context->toLocal($employee->user->last_login_at)->format('d M Y, H:i') : 'Never',
                ]" />
            @else
                <p class="text-[13px] text-ink-3">This employee has no login. They cannot use the mobile app until one is created.</p>
            @endif
        </x-card>
    @endcan
</div>
