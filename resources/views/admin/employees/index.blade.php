@extends('layouts.app')

@section('title', 'الموظفون')
@section('subtitle', $employees->total() . ' موظف')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الموظفون' => null]" />
@endsection

@section('content')
    <x-layout.page-header title="الموظفون" description="الكادر الإداري للمركز.">
        <x-slot:actions>
            @canDo('employees.manage')
                <x-ui.button :href="route('admin.employees.create')" icon="plus">موظف جديد</x-ui.button>
            @endcanDo
        </x-slot:actions>
    </x-layout.page-header>

    <x-ui.filters :action="route('admin.employees.index')" placeholder="ابحث بالاسم أو الرقم أو الهاتف…">
        <x-slot:fields>
            <x-form.select name="position" label="الوظيفة" :options="$positions" :selected="request('position')" placeholder="كل الوظائف" />
            <x-form.select name="status" label="الحالة" :options="$statuses" :selected="request('status')" placeholder="كل الحالات" />
        </x-slot:fields>
    </x-ui.filters>

    <x-ui.card padded="false">
        @if ($employees->isEmpty())
            <x-ui.empty-state icon="briefcase" title="لا يوجد موظفون" />
        @else
            <x-ui.table :headers="array_filter([
                'الموظف',
                'الهاتف',
                'الوظيفة',
                'تاريخ التعيين',
                $canSeeSalaries ? ['label' => 'الراتب الأساسي', 'align' => 'end'] : null,
                'الحالة',
                ['label' => '', 'align' => 'end'],
            ])">
                @foreach ($employees as $employee)
                    <tr class="hover:bg-ink-50">
                        <td class="px-3 py-3">
                            <a href="{{ route('admin.employees.show', $employee) }}" class="block">
                                <span class="block font-medium text-ink-900">{{ $employee->full_name }}</span>
                                <span class="block font-mono text-xs text-ink-400" dir="ltr">{{ $employee->employee_number }}</span>
                            </a>
                        </td>
                        <td class="px-3 py-3 font-mono text-ink-600" dir="ltr">{{ $employee->phone }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ $positions[$employee->position] ?? $employee->position }}</td>
                        <td class="whitespace-nowrap px-3 py-3 text-ink-600">{{ $employee->employment_date->format('Y-m-d') }}</td>

                        {{-- Salary is a separate permission from seeing the roster --}}
                        @if ($canSeeSalaries)
                            <td class="px-3 py-3 text-end tabular-nums text-ink-900">{{ money($employee->base_salary) }}</td>
                        @endif

                        <td class="px-3 py-3"><x-ui.status type="person" :value="$employee->status" /></td>
                        <td class="px-3 py-3 text-end">
                            <x-ui.button :href="route('admin.employees.show', $employee)" variant="ghost" size="sm">عرض</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $employees->links() }}</div>
@endsection
