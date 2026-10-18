<x-tabs class="mb-6" :active="$active" :tabs="[
    ['key' => 'locations', 'label' => 'Locations', 'icon' => 'map-pin', 'href' => route('admin.work-locations')],
    ['key' => 'policies', 'label' => 'Attendance policies', 'icon' => 'shield-check', 'href' => route('admin.work-locations.policies')],
]" />
