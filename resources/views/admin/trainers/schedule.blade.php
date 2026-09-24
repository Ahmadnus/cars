@extends('layouts.app')

@section('title', 'جدول المدرب')
@section('subtitle', $trainer->full_name)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="[
        'المدربون' => route('admin.trainers.index'),
        $trainer->full_name => route('admin.trainers.show', $trainer),
        'الجدول' => null,
    ]" />
@endsection

@section('content')
    <x-layout.page-header
        :title="'جدول ' . $trainer->full_name"
        :description="$start->format('Y-m-d') . ' — ' . $end->format('Y-m-d')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('admin.trainers.schedule', [$trainer, 'date' => $start->copy()->subWeek()->toDateString()])"
                         variant="secondary" size="sm" icon="chevron-right">الأسبوع السابق</x-ui.button>
            <x-ui.button :href="route('admin.trainers.schedule', [$trainer, 'date' => $start->copy()->addWeek()->toDateString()])"
                         variant="secondary" size="sm">الأسبوع التالي</x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        @foreach (collect(range(0, 6))->map(fn ($i) => $start->copy()->addDays($i)) as $day)
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
                                <span class="block truncate text-sm text-ink-900">{{ $session->trainee->full_name }}</span>
                                <span class="block truncate text-xs text-ink-400">{{ $session->vehicle?->name }}</span>
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
@endsection
