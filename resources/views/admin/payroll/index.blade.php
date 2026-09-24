@extends('layouts.app')

@section('title', 'كشوف الرواتب')
@section('subtitle', $period)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الرواتب' => null]" />
@endsection

@section('content')
    <x-layout.page-header title="الرواتب الشهرية" description="احتساب وصرف رواتب الموظفين.">
        <x-slot:actions>
            <form method="GET" action="{{ route('admin.payroll.index') }}">
                <select name="period" onchange="this.form.submit()"
                        class="rounded-lg border-ink-300 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                    @foreach ($periods as $value => $label)
                        <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>

            @canDo('payroll.create')
                <form method="POST" action="{{ route('admin.payroll.generate') }}">
                    @csrf
                    <input type="hidden" name="period" value="{{ $period }}">
                    <x-ui.button type="submit" icon="plus">إنشاء كشوف الشهر</x-ui.button>
                </form>
            @endcanDo
        </x-slot:actions>
    </x-layout.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ui.stat-card label="عدد الكشوف" :value="$totals['count']" icon="receipt" tone="neutral" />
        <x-ui.stat-card label="إجمالي الرواتب" :value="money($totals['net'])" icon="banknote" tone="brand" />
        <x-ui.stat-card label="المصروف" :value="money($totals['paid'])" icon="check" tone="success" />
        <x-ui.stat-card label="المتبقي" :value="money($totals['remaining'])" icon="alert" :tone="$totals['remaining'] > 0 ? 'warning' : 'success'" />
    </div>

    <x-ui.card padded="false">
        @if ($payrolls->isEmpty())
            <x-ui.empty-state
                icon="receipt"
                title="لا توجد كشوف رواتب لهذا الشهر"
                description="اضغط «إنشاء كشوف الشهر» لاحتساب رواتب جميع الموظفين النشطين."
            />
        @else
            <x-ui.table :headers="[
                'الموظف', 'الوظيفة',
                ['label' => 'الأساسي', 'align' => 'end'],
                ['label' => 'البدلات', 'align' => 'end'],
                ['label' => 'المكافآت', 'align' => 'end'],
                ['label' => 'الاستقطاعات', 'align' => 'end'],
                ['label' => 'أقساط السلف', 'align' => 'end'],
                ['label' => 'الصافي', 'align' => 'end'],
                'الحالة',
                ['label' => '', 'align' => 'end'],
            ]">
                @foreach ($payrolls as $payroll)
                    <tr class="hover:bg-ink-50">
                        <td class="px-3 py-3 font-medium text-ink-900">{{ $payroll->employee->full_name }}</td>
                        <td class="px-3 py-3 text-xs text-ink-500">
                            {{ \App\Http\Controllers\Admin\EmployeeController::positions()[$payroll->employee->position] ?? '' }}
                        </td>
                        <td class="px-3 py-3 text-end tabular-nums">{{ money($payroll->base_salary, false) }}</td>
                        <td class="px-3 py-3 text-end tabular-nums">{{ money($payroll->allowances, false) }}</td>
                        <td class="px-3 py-3 text-end tabular-nums text-emerald-700">{{ money($payroll->bonuses, false) }}</td>
                        <td class="px-3 py-3 text-end tabular-nums text-rose-700">{{ money($payroll->deductions, false) }}</td>
                        <td class="px-3 py-3 text-end tabular-nums text-amber-700">{{ money($payroll->advance_deductions, false) }}</td>
                        <td class="px-3 py-3 text-end font-semibold tabular-nums text-ink-900">{{ money($payroll->net_salary, false) }}</td>
                        <td class="px-3 py-3"><x-ui.status type="payroll" :value="$payroll->status" /></td>
                        <td class="px-3 py-3 text-end">
                            <x-ui.button :href="route('admin.payroll.show', $payroll)" variant="ghost" size="sm">تفاصيل</x-ui.button>
                        </td>
                    </tr>
                @endforeach

                <x-slot:footer>
                    <tr>
                        <td colspan="7" class="px-3 py-3 text-ink-700">الإجمالي</td>
                        <td class="px-3 py-3 text-end tabular-nums text-ink-900">{{ money($totals['net'], false) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </x-slot:footer>
            </x-ui.table>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $payrolls->links() }}</div>
@endsection
