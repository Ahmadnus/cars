@extends('layouts.app')

@section('title', $trainer->full_name)
@section('subtitle', 'رقم المدرب: ' . $trainer->trainer_number)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المدربون' => route('admin.trainers.index'), $trainer->full_name => null]" />
@endsection

@section('content')
@php $canSeePay = isset($currentRule) || isset($records); @endphp

<x-ui.card class="mb-5">
    <div class="flex flex-wrap items-start gap-4">
        <span class="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-ink-100 text-xl font-semibold text-ink-600">
            {{ mb_substr($trainer->full_name, 0, 1) }}
        </span>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold text-ink-900">{{ $trainer->full_name }}</h2>
                <x-ui.status type="person" :value="$trainer->status" />
            </div>

            <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm text-ink-500">
                <div class="flex items-center gap-1.5">
                    <x-ui.icon name="phone" class="size-3.5" />
                    <dd class="font-mono" dir="ltr">{{ $trainer->phone }}</dd>
                </div>
                <div><dt class="inline">التعيين:</dt> <dd class="inline text-ink-700">{{ $trainer->employment_date->format('Y-m-d') }}</dd></div>
                <div><dt class="inline">الفرع:</dt> <dd class="inline text-ink-700">{{ $trainer->branch?->name }}</dd></div>
                <div>
                    <dt class="inline">الدوام:</dt>
                    <dd class="inline font-mono text-ink-700" dir="ltr">
                        {{ short_time($trainer->work_start_time) }} - {{ short_time($trainer->work_end_time) }}
                    </dd>
                </div>
            </dl>
        </div>

        <div class="flex flex-wrap gap-2">
            <x-ui.button :href="route('admin.trainers.schedule', $trainer)" variant="secondary" size="sm" icon="calendar">الجدول</x-ui.button>
            @canDo('trainers.update')
                <x-ui.button :href="route('admin.trainers.edit', $trainer)" variant="secondary" size="sm" icon="edit">تعديل</x-ui.button>
            @endcanDo
        </div>
    </div>
</x-ui.card>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <x-ui.stat-card label="حصص منجزة (الشهر)" :value="$stats['completed']" icon="check" tone="success" />
    <x-ui.stat-card label="حصص مجدولة" :value="$stats['scheduled']" icon="calendar" tone="info" />
    <x-ui.stat-card label="حصص ملغاة" :value="$stats['cancelled']" icon="x" tone="neutral" />
    <x-ui.stat-card label="ساعات التدريب" :value="$stats['hours']" icon="clock" tone="brand" />
</div>

<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <x-ui.card padded="false" title="الحصص القادمة">
            @if ($upcoming->isEmpty())
                <x-ui.empty-state icon="calendar" title="لا توجد حصص قادمة" />
            @else
                <x-ui.table :headers="['التاريخ', 'الوقت', 'المتدرب', '']">
                    @foreach ($upcoming as $session)
                        <tr class="hover:bg-ink-50">
                            <td class="whitespace-nowrap px-3 py-2.5">{{ $session->scheduled_date->format('Y-m-d') }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 font-mono text-ink-600" dir="ltr">{{ $session->timeRange() }}</td>
                            <td class="px-3 py-2.5 text-ink-800">{{ $session->trainee->full_name }}</td>
                            <td class="px-3 py-2.5 text-end">
                                <x-ui.button :href="route('admin.sessions.show', $session)" variant="ghost" size="sm">تفاصيل</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>

        @isset($records)
            <x-ui.card padded="false" title="كشوف الأجور">
                @if ($records->isEmpty())
                    <x-ui.empty-state icon="wallet" title="لا توجد كشوف أجور" />
                @else
                    <x-ui.table :headers="['الشهر', ['label' => 'الحصص', 'align' => 'center'], ['label' => 'الإجمالي', 'align' => 'end'], ['label' => 'المصروف', 'align' => 'end'], 'الحالة', '']">
                        @foreach ($records as $record)
                            <tr>
                                <td class="whitespace-nowrap px-3 py-2.5">{{ $record->period }}</td>
                                <td class="px-3 py-2.5 text-center tabular-nums">{{ $record->lessons_count }}</td>
                                <td class="px-3 py-2.5 text-end tabular-nums">{{ money($record->net_amount) }}</td>
                                <td class="px-3 py-2.5 text-end tabular-nums text-emerald-700">{{ money($record->paid_amount) }}</td>
                                <td class="px-3 py-2.5"><x-ui.status type="compensation" :value="$record->status" /></td>
                                <td class="px-3 py-2.5 text-end">
                                    <x-ui.button :href="route('admin.trainer-compensation.show', $record)" variant="ghost" size="sm">عرض</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
        @endisset
    </div>

    <div class="space-y-5">
        <x-ui.card padded="false" :title="'المتدربون المسندون (' . $trainees->count() . ')'">
            @if ($trainees->isEmpty())
                <x-ui.empty-state icon="users" title="لا يوجد متدربون" />
            @else
                <ul class="divide-y divide-ink-100">
                    @foreach ($trainees as $item)
                        <li>
                            <a href="{{ route('admin.trainees.show', $item) }}" class="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-ink-50">
                                <span class="min-w-0 truncate text-sm text-ink-800">{{ $item->full_name }}</span>
                                <x-ui.status type="trainee" :value="$item->status" class="shrink-0" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        @isset($currentRule)
            <x-ui.card title="قاعدة الأجر الحالية">
                @if ($currentRule)
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-500">النموذج</dt>
                            <dd class="font-medium text-ink-900">{{ $currentRule->modelLabel() }}</dd>
                        </div>
                        @if ($currentRule->usesSalary())
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-500">الراتب الأساسي</dt>
                                <dd class="tabular-nums text-ink-800">{{ money($currentRule->base_salary) }}</dd>
                            </div>
                        @endif
                        @if ($currentRule->usesPerLesson())
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-500">لكل حصة</dt>
                                <dd class="tabular-nums text-ink-800">{{ money($currentRule->per_lesson_rate) }}</dd>
                            </div>
                        @endif
                        @if ($currentRule->usesPercentage())
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-500">النسبة</dt>
                                <dd class="tabular-nums text-ink-800">{{ percent($currentRule->revenue_percentage, 0) }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between gap-3 border-t border-ink-100 pt-2">
                            <dt class="text-ink-500">سارية من</dt>
                            <dd class="text-ink-700">{{ $currentRule->effective_from->format('Y-m-d') }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="text-sm text-ink-400">لم تُحدَّد قاعدة أجر لهذا المدرب بعد.</p>
                @endif

                @canDo('trainer_compensation.manage')
                    <x-ui.button :href="route('admin.trainer-compensation.rules')" variant="secondary" size="sm" class="mt-3 w-full">
                        إدارة قواعد الأجور
                    </x-ui.button>
                @endcanDo
            </x-ui.card>
        @endisset

        <x-ui.card title="المركبات المسندة">
            @forelse ($trainer->vehicles as $vehicle)
                <div class="flex items-center justify-between gap-3 border-b border-ink-100 py-2 first:pt-0 last:border-0 last:pb-0">
                    <span class="text-sm text-ink-800">{{ $vehicle->name }}</span>
                    <span class="font-mono text-xs text-ink-500" dir="ltr">{{ $vehicle->plate_number }}</span>
                </div>
            @empty
                <p class="text-sm text-ink-400">لا توجد مركبات مسندة.</p>
            @endforelse
        </x-ui.card>
    </div>
</div>
@endsection
