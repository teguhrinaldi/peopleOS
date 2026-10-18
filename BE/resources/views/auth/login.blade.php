@extends('layouts.guest')
@section('title', 'Sign in')

@section('content')
<div class="w-full max-w-[420px]">
    <div class="card p-8 sm:p-10">
        <div class="flex flex-col items-center text-center">
            <x-logo size="lg" />
            <p class="mt-2 text-[13.5px] text-ink-3">Workforce &amp; HR Management</p>
            <h1 class="mt-7 text-base font-semibold text-ink">Sign in to your account</h1>
        </div>

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4" novalidate>
            @csrf
            <x-input name="email" type="email" label="Email address" placeholder="you@company.com" icon="mail" autocomplete="username" autofocus required />
            <div x-data="{ show: false }" class="relative">
                <label for="f-password" class="mb-1.5 block text-[13px] font-medium text-ink">Password</label>
                <div class="relative">
                    <x-icon name="lock" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-3" />
                    <input id="f-password" name="password" :type="show ? 'text' : 'password'" type="password" class="ctl pl-9 pr-10" placeholder="Enter your password" autocomplete="current-password" required>
                    <button type="button" @click="show = !show" class="absolute right-2 top-1/2 grid size-7 -translate-y-1/2 place-items-center rounded-md text-ink-3 hover:text-ink" :aria-label="show ? 'Hide password' : 'Show password'">
                        <span x-show="!show"><x-icon name="eye" class="size-4" /></span><span x-show="show" x-cloak><x-icon name="eye-off" class="size-4" /></span>
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <label class="flex cursor-pointer items-center gap-2 text-[13px] text-ink-2">
                    <input type="checkbox" name="remember" value="1" class="size-4 rounded accent-[var(--primary)]" @checked(old('remember'))> Remember me
                </label>
                <span class="text-[13px] text-ink-3" title="Password resets are handled by your administrator.">Forgot password? Contact admin</span>
            </div>

            <x-button type="submit" variant="navy" size="lg" class="w-full">Sign in</x-button>
        </form>
    </div>
    <p class="mt-6 text-center text-xs text-ink-3">Manage people. Simplify work.</p>
</div>
@endsection
