<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'تسجيل الدخول') — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ink-100">
<div class="grid min-h-screen lg:grid-cols-2">

    {{-- Brand panel: hidden on small screens so the form owns the viewport --}}
    <div class="relative hidden bg-brand-800 p-12 lg:flex lg:flex-col lg:justify-between">
        <div class="flex items-center gap-3 text-white">
            <span class="flex size-10 items-center justify-center rounded-xl bg-white/15 text-lg font-bold">
                {{ mb_substr(config('app.name'), 0, 1) }}
            </span>
            <span class="text-sm font-semibold">{{ config('app.name') }}</span>
        </div>

        <div class="max-w-md text-white">
            <h2 class="text-2xl font-bold leading-relaxed">
                نظام متكامل لإدارة مركز تدريب القيادة
            </h2>
            <p class="mt-4 text-sm leading-loose text-brand-100">
                إدارة المتدربين والمدربين والحصص التدريبية والمواعيد والمركبات،
                مع متابعة دقيقة للإيرادات والمصاريف والرواتب والأرباح.
            </p>

            <ul class="mt-8 space-y-3 text-sm text-brand-100">
                @foreach (['جدولة الحصص ومنع تعارض المواعيد', 'رصيد حصص دقيق وقابل للتدقيق', 'تقارير مالية وأرباح لحظية', 'صلاحيات دقيقة لكل موظف'] as $feature)
                    <li class="flex items-center gap-2.5">
                        <span class="flex size-5 items-center justify-center rounded-full bg-white/15">
                            <x-ui.icon name="check" class="size-3" />
                        </span>
                        {{ $feature }}
                    </li>
                @endforeach
            </ul>
        </div>

        <p class="text-xs text-brand-200">© {{ date('Y') }} — جميع الحقوق محفوظة</p>
    </div>

    <div class="flex items-center justify-center p-6 sm:p-12">
        <div class="w-full max-w-sm">
            @yield('content')
        </div>
    </div>
</div>

<x-ui.toast-host />
</body>
</html>
