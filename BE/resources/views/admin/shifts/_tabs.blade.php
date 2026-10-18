<x-tabs class="mb-6" :active="$active" :tabs="[
    ['key' => 'shifts', 'label' => 'Shifts', 'icon' => 'calendar-clock', 'href' => route('admin.shifts')],
    ['key' => 'assignments', 'label' => 'Overrides', 'icon' => 'git-branch', 'href' => route('admin.shifts.assignments')],
    ['key' => 'calendars', 'label' => 'Working calendars', 'icon' => 'calendar-days', 'href' => route('admin.shifts.calendars')],
    ['key' => 'holidays', 'label' => 'Holidays', 'icon' => 'flag', 'href' => route('admin.shifts.holidays')],
]" />
