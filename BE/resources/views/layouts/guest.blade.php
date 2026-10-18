<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    @include('layouts.partials.head')
</head>
<body class="relative min-h-screen overflow-x-hidden antialiased">
    {{-- Soft wave backdrop as in the reference login. --}}
    <div class="pointer-events-none absolute inset-0 -z-0 bg-gradient-to-b from-primary-soft/70 via-bg to-bg" aria-hidden="true"></div>
    <svg class="pointer-events-none absolute inset-x-0 bottom-0 h-72 w-full text-primary/[0.07]" viewBox="0 0 1440 320" preserveAspectRatio="none" aria-hidden="true"><path fill="currentColor" d="M0 224l60-21.300C120 181 240 139 360 149.300 480 160 600 224 720 234.700c120 10.300 240-31.700 360-53.400C1200 160 1320 160 1380 160l60 0v160H0z"/></svg>
    <main class="relative z-10 grid min-h-screen place-items-center px-4 py-10">
        @yield('content')
    </main>
    <x-flash />
</body>
</html>
