@extends('layouts.app')

@section('title', 'المتدربون')
@section('subtitle', $trainees->total() . ' متدرب')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المتدربون' => null]" />
@endsection

@section('content')
    <x-layout.page-header title="إدارة المتدربين" description="بحث وتصفية وإدارة ملفات المتدربين.">
        <x-slot:actions>
            @canDo('trainees.create')
                <x-ui.button :href="route('admin.trainees.create')" icon="plus">متدرب جديد</x-ui.button>
            @endcanDo
        </x-slot:actions>
    </x-layout.page-header>

    <x-ui.filters :action="route('admin.trainees.index')" placeholder="ابحث بالاسم أو الرقم أو الهاتف أو الرقم الوطني…">
        <x-slot:fields>
            <x-form.select name="status" label="الحالة" :options="$statuses" :selected="request('status')" placeholder="كل الحالات" />
            <x-form.select name="trainer_id" label="المدرب" :options="$trainers" :selected="request('trainer_id')" placeholder="كل المدربين" />
            <x-form.input name="from" type="date" label="مسجّل من" :value="request('from')" />
            <x-form.input name="to" type="date" label="مسجّل إلى" :value="request('to')" />
        </x-slot:fields>
    </x-ui.filters>

    <x-ui.card padded="false">
        @if ($trainees->isEmpty())
            <x-ui.empty-state
                icon="users"
                title="لا يوجد متدربون مطابقون"
                description="جرّب تعديل معايير البحث أو أضف متدرباً جديداً."
            >
                @canDo('trainees.create')
                    <x-slot:action>
                        <x-ui.button :href="route('admin.trainees.create')" icon="plus" size="sm">متدرب جديد</x-ui.button>
                    </x-slot:action>
                @endcanDo
            </x-ui.empty-state>
        @else
            <x-ui.table :headers="['المتدرب', 'رقم الهاتف', 'المدرب', 'نوع الرخصة', 'تاريخ التسجيل', 'الحالة', ['label' => '', 'align' => 'end']]">
                @foreach ($trainees as $trainee)
                    <tr class="transition hover:bg-ink-50">
                        <td class="px-3 py-3">
                            <a href="{{ route('admin.trainees.show', $trainee) }}" class="flex items-center gap-3">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700">
                                    {{ mb_substr($trainee->full_name, 0, 1) }}
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-ink-900">{{ $trainee->full_name }}</span>
                                    <span class="block font-mono text-xs text-ink-400" dir="ltr">{{ $trainee->trainee_number }}</span>
                                </span>
                            </a>
                        </td>
                        <td class="px-3 py-3 font-mono text-ink-600" dir="ltr">{{ $trainee->phone }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ $trainee->trainer?->full_name ?? '—' }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ $trainee->license_type }}</td>
                        <td class="whitespace-nowrap px-3 py-3 text-ink-600">{{ $trainee->registration_date->format('Y-m-d') }}</td>
                        <td class="px-3 py-3"><x-ui.status type="trainee" :value="$trainee->status" /></td>
                        <td class="px-3 py-3 text-end">
                            <div class="flex items-center justify-end gap-1">
                                <x-ui.button :href="route('admin.trainees.show', $trainee)" variant="ghost" size="sm">عرض</x-ui.button>
                                @canDo('trainees.update')
                                    <x-ui.button :href="route('admin.trainees.edit', $trainee)" variant="ghost" size="sm" icon="edit" />
                                @endcanDo
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $trainees->links() }}</div>
@endsection
