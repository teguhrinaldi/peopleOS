<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="light dark">
<title>@yield('title', 'PeopleOS') &middot; {{ config('app.name', 'PeopleOS') }}</title>
{{-- Resolve the theme before first paint: stored choice, otherwise the OS preference. --}}
<script>
    (function () {
        try {
            var stored = JSON.parse(localStorage.getItem('peopleos.theme'));
            var theme = stored === 'dark' || stored === 'light' ? stored : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.dataset.theme = theme;
        } catch (e) { document.documentElement.dataset.theme = 'light'; }
    })();
</script>
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
@vite(['resources/css/app.css', 'resources/js/app.js'])
