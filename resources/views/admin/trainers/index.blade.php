@extends('layouts.app')

@section('title', 'المدربون')
@section('subtitle', $trainers->total() . ' مدرب')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المدربون' => null]" />
@endsection

@section('content')
    <x-layout.page-header title="المدربون" description="إدارة ملفات المدربين وجداولهم.">
        <x-slot:actions>
            @canDo('trainers.create')
                <x-ui.button :href="route('admin.trainers.create')" icon="plus">مدرب جديد</x-ui.button>
            @endcanDo
        </x-slot:actions>
    </x-layout.page-header>

    <x-ui.filters :action="route('admin.trainers.index')" placeholder="ابحث بالاسم أو الرقم أو الهاتف…">
        <x-slot:fields>
            <x-form.select name="status" label="الحالة" :options="$statuses" :selected="request('status')" placeholder="كل الحالات" />
        </x-slot:fields>
    </x-ui.filters>

    <x-ui.card padded="false">
        @if ($trainers->isEmpty())
            <x-ui.empty-state icon="badge" title="لا يوجد مدربون" description="أضف أول مدرب لبدء جدولة الحصص." />
        @else
            <x-ui.table :headers="['المدرب', 'الهاتف', 'تاريخ التعيين', ['label' => 'المتدربون', 'align' => 'center'], ['label' => 'حصص منجزة', 'align' => 'center'], 'الحالة', ['label' => '', 'align' => 'end']]">
                @foreach ($trainers as $trainer)
                    <tr class="hover:bg-ink-50">
                        <td class="px-3 py-3">
                            <a href="{{ route('admin.trainers.show', $trainer) }}" class="flex items-center gap-3">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-ink-100 text-xs font-semibold text-ink-600">
                                    {{ mb_substr($trainer->full_name, 0, 1) }}
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-ink-900">{{ $trainer->full_name }}</span>
                                    <span class="block font-mono text-xs text-ink-400" dir="ltr">{{ $trainer->trainer_number }}</span>
                                </span>
                            </a>
                        </td>
                        <td class="px-3 py-3 font-mono text-ink-600" dir="ltr">{{ $trainer->phone }}</td>
                        <td class="whitespace-nowrap px-3 py-3 text-ink-600">{{ $trainer->employment_date->format('Y-m-d') }}</td>
                        <td class="px-3 py-3 text-center tabular-nums text-ink-700">{{ $trainer->trainees_count }}</td>
                        <td class="px-3 py-3 text-center tabular-nums text-ink-700">{{ $trainer->completed_sessions_count }}</td>
                        <td class="px-3 py-3"><x-ui.status type="person" :value="$trainer->status" /></td>
                        <td class="px-3 py-3 text-end">
                            <div class="flex justify-end gap-1">
                                <x-ui.button :href="route('admin.trainers.schedule', $trainer)" variant="ghost" size="sm" icon="calendar" />
                                <x-ui.button :href="route('admin.trainers.show', $trainer)" variant="ghost" size="sm">عرض</x-ui.button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $trainers->links() }}</div>
@endsection
