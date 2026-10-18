@extends('layouts.admin')
@section('title', $title)

@section('content')
<x-page-header :title="$title" :breadcrumbs="array_filter([$group ? [$group] : null, [$title]])" />

<x-card :padded="false">
    <x-empty-state :icon="$icon" title="This module arrives in a later release" :text="$description">
        <x-button variant="outline" :href="route('admin.dashboard')" icon="layout-dashboard">Back to dashboard</x-button>
    </x-empty-state>
</x-card>
@endsection
