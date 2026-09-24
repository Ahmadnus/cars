@extends('layouts.app')

@section('title', 'تقويم الحصص')
@section('subtitle', $start->format('Y-m-d') . ' — ' . $end->format('Y-m-d'))

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['التقويم' => null]" />
@endsection

@section('content')
@php
    $query = request()->except(['date', 'view', 'page']);
    // Full class strings, not interpolated fragments: Tailwind scans source
    // text, so a class built at runtime would never be generated.
    $chip = [
        'scheduled' => 'bg-brand-50 text-brand-700',
        'completed' => 'bg-emerald-50 text-emerald-700',
        'cancelled' => 'bg-ink-100 text-ink-500',
        'postponed' => 'bg-amber-50 text-amber-700',
        'no_show' => 'bg-rose-50 text-rose-700',
    ];
@endphp

<x-layout.page-header title="تقويم الحصص" description="جدولة الحصص التدريبية ومتابعة المواعيد.">
    <x-slot:actions>
        @canDo('appointments.create')
            <x-ui.button :href="route('admin.sessions.create')" icon="plus">حجز حصة</x-ui.button>
        @endcanDo
    </x-slot:actions>
</x-layout.page-header>

{{-- View switcher and navigation --}}
<div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-ink-200 bg-white p-3">
    <div class="flex items-center gap-1">
        <x-ui.button :href="route('admin.calendar.index', array_merge($query, ['view' => $view, 'date' => $previous]))"
                     variant="secondary" size="sm" icon="chevron-right" />
        <x-ui.button :href="route('admin.calendar.index', array_merge($query, ['view' => $view, 'date' => now()->toDateString()]))"
                     variant="secondary" size="sm">اليوم</x-ui.button>
        <x-ui.button :href="route('admin.calendar.index', array_merge($query, ['view' => $view, 'date' => $next]))"
                     variant="secondary" size="sm" icon="chevron-left" />
    </div>

    <p class="text-sm font-medium text-ink-800">
        {{ $view === 'month' ? $anchor->translatedFormat('F Y') : $start->translatedFormat('j F') . ' — ' . $end->translatedFormat('j F Y') }}
    </p>

    <div class="ms-auto flex rounded-lg border border-ink-200 p-0.5">
        @foreach (['day' => 'يومي', 'week' => 'أسبوعي', 'month' => 'شهري'] as $key => $label)
            <a href="{{ route('admin.calendar.index', array_merge($query, ['view' => $key, 'date' => $anchor->toDateString()])) }}"
               @class([
                   'rounded-md px-3 py-1.5 text-sm transition',
                   'bg-brand-600 font-medium text-white' => $view === $key,
                   'text-ink-600 hover:bg-ink-100' => $view !== $key,
               ])>{{ $label }}</a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('admin.calendar.index') }}" class="flex flex-wrap items-center gap-2">
        <input type="hidden" name="view" value="{{ $view }}">
        <input type="hidden" name="date" value="{{ $anchor->toDateString() }}">

        <select name="trainer_id" onchange="this.form.submit()"
                class="rounded-lg border-ink-300 py-1.5 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <option value="">كل المدربين</option>
            @foreach ($trainers as $id => $name)
                <option value="{{ $id }}" @selected(request('trainer_id') == $id)>{{ $name }}</option>
            @endforeach
        </select>

        <select name="vehicle_id" onchange="this.form.submit()"
                class="rounded-lg border-ink-300 py-1.5 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <option value="">كل المركبات</option>
            @foreach ($vehicles as $id => $name)
                <option value="{{ $id }}" @selected(request('vehicle_id') == $id)>{{ $name }}</option>
            @endforeach
        </select>
    </form>
</div>

@if ($view === 'month')
    {{-- Month grid --}}
    <x-ui.card padded="false">
        <div class="grid grid-cols-7 border-b border-ink-200 bg-ink-50 text-center text-xs font-medium text-ink-500">
            @foreach (['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'] as $day)
                <div class="py-2">{{ $day }}</div>
            @endforeach
        </div>

        <div class="grid grid-cols-7">
            @foreach ($days as $day)
                @php $daySessions = $sessions[$day->toDateString()] ?? collect(); @endphp
                <div @class([
                        'min-h-28 border-b border-s border-ink-100 p-1.5',
                        'bg-ink-50/60' => $day->month !== $anchor->month,
                    ])>
                    <div class="mb-1 flex items-center justify-between">
                        <span @class([
                                'text-xs tabular-nums',
                                'flex size-5 items-center justify-center rounded-full bg-brand-600 font-semibold text-white' => $day->isToday(),
                                'text-ink-400' => ! $day->isToday(),
                            ])>{{ $day->day }}</span>
                        @if ($daySessions->count() > 2)
                            <span class="text-[10px] text-ink-400">{{ $daySessions->count() }}</span>
                        @endif
                    </div>

                    <div class="space-y-0.5">
                        @foreach ($daySessions->take(3) as $session)
                            <a href="{{ route('admin.sessions.show', $session) }}"
                               class="block truncate rounded px-1 py-0.5 text-[11px] hover:opacity-80 {{ $chip[$session->status] ?? $chip['cancelled'] }}">
                                <span class="font-mono" dir="ltr">{{ short_time($session->start_time) }}</span>
                                {{ $session->trainee->full_name }}
                            </a>
                        @endforeach

                        @if ($daySessions->count() > 3)
                            <a href="{{ route('admin.calendar.index', array_merge($query, ['view' => 'day', 'date' => $day->toDateString()])) }}"
                               class="block px-1 text-[10px] text-brand-600 hover:underline">
                                + {{ $daySessions->count() - 3 }} أخرى
                            </a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-ui.card>
@else
    {{-- Day and week: a column per day --}}
    <div class="grid gap-3 {{ $view === 'day' ? '' : 'md:grid-cols-2 xl:grid-cols-4' }}">
        @foreach ($days as $day)
            @php $daySessions = $sessions[$day->toDateString()] ?? collect(); @endphp

            <x-ui.card padded="false" @class(['ring-2 ring-brand-200' => $day->isToday()])>
                <header class="flex items-center justify-between border-b border-ink-200 px-4 py-2.5">
                    <div>
                        <p class="text-sm font-semibold text-ink-900">{{ $day->translatedFormat('l') }}</p>
                        <p class="text-xs text-ink-400">{{ $day->format('Y-m-d') }}</p>
                    </div>
                    <x-ui.badge :tone="$daySessions->isEmpty() ? 'neutral' : 'brand'">{{ $daySessions->count() }}</x-ui.badge>
                </header>

                <div class="divide-y divide-ink-100">
                    @forelse ($daySessions as $session)
                        <a href="{{ route('admin.sessions.show', $session) }}" class="flex gap-3 px-4 py-2.5 hover:bg-ink-50">
                            <span class="w-14 shrink-0 font-mono text-xs tabular-nums text-ink-500" dir="ltr">
                                {{ short_time($session->start_time) }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-ink-900">{{ $session->trainee->full_name }}</span>
                                <span class="block truncate text-xs text-ink-400">
                                    {{ $session->trainer->full_name }}@if ($session->vehicle) · {{ $session->vehicle->name }}@endif
                                </span>
                            </span>
                            <x-ui.status type="session" :value="$session->status" class="shrink-0" />
                        </a>
                    @empty
                        <p class="px-4 py-6 text-center text-xs text-ink-400">لا توجد حصص</p>
                    @endforelse
                </div>
            </x-ui.card>
        @endforeach
    </div>
@endif
@endsection
