@extends('layouts.guest')
@section('title', $heading)

@section('content')
<div class="w-full max-w-lg text-center">
    <a href="{{ url('/') }}" class="inline-block" aria-label="PeopleOS"><x-logo /></a>
    <div class="card mt-8 px-8 py-12">
        <span class="mx-auto grid size-16 place-items-center rounded-2xl {{ $tone ?? 'bg-primary-soft text-primary-ink' }}"><x-icon :name="$icon" class="size-8" /></span>
        <p class="mt-6 text-sm font-semibold tracking-wider text-ink-3">ERROR {{ $code }}</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink">{{ $heading }}</h1>
        <p class="mx-auto mt-3 max-w-sm text-[14px] leading-relaxed text-ink-2">{{ $message }}</p>
        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
            <x-button :href="auth()->check() ? route('admin.dashboard') : route('login')" :icon="auth()->check() ? 'layout-dashboard' : 'log-in'">{{ auth()->check() ? 'Back to dashboard' : 'Go to sign in' }}</x-button>
            <x-button variant="outline" onclick="history.length > 1 ? history.back() : location.assign('{{ url('/') }}')">Go back</x-button>
        </div>
    </div>
</div>
@endsection
