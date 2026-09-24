@extends('layouts.app')

@section('title', 'الحصص التدريبية')
@section('subtitle', $sessions->total() . ' حصة')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الحصص التدريبية' => null]" />
@endsection

@section('content')
    <x-layout.page-header title="الحصص التدريبية" description="سجل كامل للحصص المجدولة والمنجزة.">
        <x-slot:actions>
            <x-ui.button :href="route('admin.calendar.index')" variant="secondary" icon="calendar">عرض التقويم</x-ui.button>
            @canDo('appointments.create')
                <x-ui.button :href="route('admin.sessions.create')" icon="plus">حجز حصة</x-ui.button>
            @endcanDo
        </x-slot:actions>
    </x-layout.page-header>

    <x-ui.filters :action="route('admin.sessions.index')" placeholder="ابحث باسم المتدرب أو رقمه…">
        <x-slot:fields>
            <x-form.select name="status" label="الحالة" :options="$statuses" :selected="request('status')" placeholder="كل الحالات" />
            <x-form.select name="trainer_id" label="المدرب" :options="$trainers" :selected="request('trainer_id')" placeholder="كل المدربين" />
            <x-form.input name="from" type="date" label="من تاريخ" :value="request('from')" />
            <x-form.input name="to" type="date" label="إلى تاريخ" :value="request('to')" />
        </x-slot:fields>
    </x-ui.filters>

    <x-ui.card padded="false">
        @if ($sessions->isEmpty())
            <x-ui.empty-state icon="steering" title="لا توجد حصص مطابقة" description="جرّب توسيع نطاق التاريخ أو تغيير الفلاتر." />
        @else
            <x-ui.table :headers="['التاريخ', 'الوقت', 'المتدرب', 'المدرب', 'المركبة', 'الحالة', ['label' => '', 'align' => 'end']]">
                @foreach ($sessions as $session)
                    <tr class="hover:bg-ink-50">
                        <td class="whitespace-nowrap px-3 py-3">
                            <span class="block text-ink-900">{{ $session->scheduled_date->format('Y-m-d') }}</span>
                            <span class="block text-xs text-ink-400">{{ $session->scheduled_date->translatedFormat('l') }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 font-mono text-ink-600" dir="ltr">{{ $session->timeRange() }}</td>
                        <td class="px-3 py-3">
                            <a href="{{ route('admin.trainees.show', $session->trainee) }}" class="font-medium text-ink-900 hover:text-brand-600">
                                {{ $session->trainee->full_name }}
                            </a>
                            <span class="block font-mono text-xs text-ink-400" dir="ltr">{{ $session->trainee->trainee_number }}</span>
                        </td>
                        <td class="px-3 py-3 text-ink-600">{{ $session->trainer->full_name }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ $session->vehicle?->name ?? '—' }}</td>
                        <td class="px-3 py-3"><x-ui.status type="session" :value="$session->status" /></td>
                        <td class="px-3 py-3 text-end">
                            <x-ui.button :href="route('admin.sessions.show', $session)" variant="ghost" size="sm">تفاصيل</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $sessions->links() }}</div>
@endsection
