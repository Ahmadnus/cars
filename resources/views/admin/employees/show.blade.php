@extends('layouts.app')

@section('title', $employee->full_name)
@section('subtitle', 'رقم الموظف: ' . $employee->employee_number)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الموظفون' => route('admin.employees.index'), $employee->full_name => null]" />
@endsection

@section('content')
<x-ui.card class="mb-5">
    <div class="flex flex-wrap items-start gap-4">
        <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-ink-100 text-lg font-semibold text-ink-600">
            {{ mb_substr($employee->full_name, 0, 1) }}
        </span>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold text-ink-900">{{ $employee->full_name }}</h2>
                <x-ui.status type="person" :value="$employee->status" />
            </div>
            <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm text-ink-500">
                <div class="flex items-center gap-1.5">
                    <x-ui.icon name="phone" class="size-3.5" />
                    <dd class="font-mono" dir="ltr">{{ $employee->phone }}</dd>
                </div>
                <div><dt class="inline">الوظيفة:</dt> <dd class="inline text-ink-700">{{ \App\Http\Controllers\Admin\EmployeeController::positions()[$employee->position] ?? $employee->position }}</dd></div>
                <div><dt class="inline">التعيين:</dt> <dd class="inline text-ink-700">{{ $employee->employment_date->format('Y-m-d') }}</dd></div>
                <div><dt class="inline">الفرع:</dt> <dd class="inline text-ink-700">{{ $employee->branch?->name }}</dd></div>
            </dl>
        </div>

        @canDo('employees.manage')
            <x-ui.button :href="route('admin.employees.edit', $employee)" variant="secondary" size="sm" icon="edit">تعديل</x-ui.button>
        @endcanDo
    </div>
</x-ui.card>

<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        @isset($payrolls)
            <x-ui.card padded="false" title="كشوف الرواتب">
                @if ($payrolls->isEmpty())
                    <x-ui.empty-state icon="receipt" title="لا توجد كشوف رواتب" />
                @else
                    <x-ui.table :headers="['الشهر', ['label' => 'الأساسي', 'align' => 'end'], ['label' => 'الاستقطاعات', 'align' => 'end'], ['label' => 'الصافي', 'align' => 'end'], ['label' => 'المصروف', 'align' => 'end'], 'الحالة', '']">
                        @foreach ($payrolls as $payroll)
                            <tr>
                                <td class="whitespace-nowrap px-3 py-2.5">{{ $payroll->period }}</td>
                                <td class="px-3 py-2.5 text-end tabular-nums">{{ money($payroll->base_salary, false) }}</td>
                                <td class="px-3 py-2.5 text-end tabular-nums text-rose-700">
                                    {{ money($payroll->deductions + $payroll->advance_deductions, false) }}
                                </td>
                                <td class="px-3 py-2.5 text-end font-semibold tabular-nums">{{ money($payroll->net_salary, false) }}</td>
                                <td class="px-3 py-2.5 text-end tabular-nums text-emerald-700">{{ money($payroll->paid_amount, false) }}</td>
                                <td class="px-3 py-2.5"><x-ui.status type="payroll" :value="$payroll->status" /></td>
                                <td class="px-3 py-2.5 text-end">
                                    <x-ui.button :href="route('admin.payroll.show', $payroll)" variant="ghost" size="sm">عرض</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
        @endisset

        @isset($advances)
            <x-ui.card padded="false" title="السلف">
                @if ($advances->isEmpty())
                    <x-ui.empty-state icon="hand-coins" title="لا توجد سلف" />
                @else
                    <x-ui.table :headers="['التاريخ', ['label' => 'القيمة', 'align' => 'end'], ['label' => 'المسدد', 'align' => 'end'], ['label' => 'المتبقي', 'align' => 'end'], 'الأقساط', 'الحالة']">
                        @foreach ($advances as $advance)
                            <tr>
                                <td class="whitespace-nowrap px-3 py-2.5">{{ $advance->granted_on->format('Y-m-d') }}</td>
                                <td class="px-3 py-2.5 text-end tabular-nums">{{ money($advance->amount, false) }}</td>
                                <td class="px-3 py-2.5 text-end tabular-nums text-emerald-700">{{ money($advance->deducted_amount, false) }}</td>
                                <td class="px-3 py-2.5 text-end tabular-nums text-amber-700">{{ money($advance->remaining_amount, false) }}</td>
                                <td class="px-3 py-2.5 text-ink-600">
                                    {{ $advance->installments_count }} × {{ money($advance->installment_amount, false) }}
                                </td>
                                <td class="px-3 py-2.5">
                                    <x-ui.badge :tone="$advance->status === 'settled' ? 'success' : 'warning'">
                                        {{ \App\Http\Controllers\Admin\EmployeeAdvanceController::statuses()[$advance->status] ?? $advance->status }}
                                    </x-ui.badge>
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
        @endisset
    </div>

    <div class="space-y-5">
        @canDo('salaries.view')
            <x-ui.card title="تفاصيل الراتب">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">الراتب الأساسي</dt>
                        <dd class="tabular-nums text-ink-900">{{ money($employee->base_salary) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">البدلات</dt>
                        <dd class="tabular-nums text-ink-900">{{ money($employee->allowances) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 border-t border-ink-200 pt-2">
                        <dt class="font-semibold text-ink-700">الإجمالي الشهري</dt>
                        <dd class="font-bold tabular-nums text-ink-900">
                            {{ money($employee->base_salary + $employee->allowances) }}
                        </dd>
                    </div>

                    @isset($outstandingAdvances)
                        @if ($outstandingAdvances > 0)
                            <div class="flex justify-between gap-3 border-t border-ink-200 pt-2">
                                <dt class="text-amber-700">سلف قائمة</dt>
                                <dd class="font-semibold tabular-nums text-amber-700">{{ money($outstandingAdvances) }}</dd>
                            </div>
                        @endif
                    @endisset
                </dl>
            </x-ui.card>
        @endcanDo

        <x-ui.card title="الدوام">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">الأوقات</dt>
                    <dd class="font-mono text-ink-800" dir="ltr">
                        {{ short_time($employee->work_start_time) }} - {{ short_time($employee->work_end_time) }}
                    </dd>
                </div>
                <div>
                    <dt class="mb-1 text-ink-500">أيام العمل</dt>
                    <dd class="flex flex-wrap gap-1">
                        @foreach ((array) $employee->working_days as $day)
                            <x-ui.badge>{{ \App\Http\Controllers\Admin\BranchController::days()[$day] ?? $day }}</x-ui.badge>
                        @endforeach
                    </dd>
                </div>
            </dl>
        </x-ui.card>

        @if ($employee->notes)
            <x-ui.card title="ملاحظات">
                <p class="whitespace-pre-line text-sm text-ink-600">{{ $employee->notes }}</p>
            </x-ui.card>
        @endif
    </div>
</div>
@endsection
