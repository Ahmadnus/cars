<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'بوابة المتدرب') — {{ settings('center.name', config('app.name')) }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ink-50 text-ink-800">

{{-- The portal deliberately has no sidebar: a trainee has exactly one page. --}}
<header class="border-b border-ink-200 bg-white">
    <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3.5 sm:px-6">
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-ink-900">
                {{ settings('center.name', config('app.name')) }}
            </p>
            <p class="text-xs text-ink-400">بوابة المتدرب</p>
        </div>

        <div class="flex items-center gap-3">
            <span class="hidden text-sm text-ink-600 sm:inline">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" size="sm">تسجيل الخروج</x-ui.button>
            </form>
        </div>
    </div>
</header>

<main class="mx-auto max-w-5xl px-4 pb-12 pt-6 sm:px-6">
    @if (session('status'))
        <x-ui.alert type="success" class="mb-5">{{ session('status') }}</x-ui.alert>
    @endif

    @yield('content')
</main>

<footer class="border-t border-ink-200 px-6 py-4 text-center text-xs text-ink-400">
    {{ settings('center.name', config('app.name')) }} — بوابة المتدرب
</footer>

<x-ui.toast-host />
@stack('scripts')
</body>
</html>
