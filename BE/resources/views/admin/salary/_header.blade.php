@php
    $tabs = [
        ['key' => 'structures', 'label' => 'Structures', 'icon' => 'layers', 'href' => route('admin.salary-structures')],
        ['key' => 'components', 'label' => 'Components', 'icon' => 'list-checks', 'href' => route('admin.salary-structures.components')],
    ];
@endphp
<x-alert tone="warning" icon="info" title="No tax or statutory rules are built in" class="mb-5">
    PeopleOS calculates pay only from the components you define here. Income tax, social security, pension and any other statutory rules differ by country: configure them as components (for example a percent-of-gross deduction) according to the rules of your jurisdiction and have them checked by a qualified person.
</x-alert>
<x-tabs :tabs="$tabs" :active="$tab" class="mb-5" />
