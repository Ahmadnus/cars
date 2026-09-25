<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'لوحة التحكم') — {{ settings('center.name', config('app.name')) }}</title>
    @php
        /*
         | Web push configuration.
         |
         | Built in a @php block rather than inline in @json(): Blade's compiler
         | mis-parses a multi-line array literal inside a directive's parentheses
         | and fails with "Unclosed '['".
         |
         | Every value is public — the Firebase web SDK ships them to the browser,
         | and the VAPID key here is the public half of a pair. The service
         | account that signs sends never leaves the server.
         */
        $pushConfig = [
            'apiKey' => config('services.fcm.web.api_key'),
            'authDomain' => config('services.fcm.web.auth_domain'),
            'projectId' => config('services.fcm.project_id'),
            'senderId' => config('services.fcm.web.sender_id'),
            'appId' => config('services.fcm.web.app_id'),
            'vapidKey' => config('services.fcm.web.vapid_key'),
        ];
    @endphp

    <script type="application/json" id="push-config">{!! json_encode($pushConfig) !!}</script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ink-50 text-ink-800">
<div x-data="{ sidebarOpen: false }" class="flex min-h-screen">

    {{-- Mobile scrim --}}
    <div x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false"
         class="fixed inset-0 z-30 bg-ink-900/40 lg:hidden" x-cloak></div>

    <x-layout.sidebar />

    <div class="flex min-w-0 flex-1 flex-col">
        <x-layout.header />

        <main class="flex-1 px-4 pb-10 pt-5 sm:px-6 lg:px-8">
            @hasSection('breadcrumbs')
                <div class="mb-4">@yield('breadcrumbs')</div>
            @endif

            @if (session('status'))
                <x-ui.alert type="success" class="mb-5">{{ session('status') }}</x-ui.alert>
            @endif

            @if ($errors->any() && ! $errors->has('error'))
                <x-ui.alert type="error" class="mb-5" title="تعذّر إتمام العملية">
                    <ul class="list-disc space-y-1 ps-5">
                        @foreach ($errors->unique() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </x-ui.alert>
            @endif

            @if ($errors->has('error'))
                <x-ui.alert type="error" class="mb-5">{{ $errors->first('error') }}</x-ui.alert>
            @endif

            @yield('content')
        </main>

        <footer class="border-t border-ink-200 px-6 py-4 text-xs text-ink-400">
            {{ settings('center.name', config('app.name')) }} — نظام إدارة مركز تدريب القيادة
        </footer>
    </div>
</div>

<x-ui.toast-host />

@stack('scripts')
</body>
</html>
