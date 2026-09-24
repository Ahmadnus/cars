@extends('layouts.app')

@section('title', 'كشف راتب')
@section('subtitle', $payroll->employee->full_name . ' — ' . $payroll->period)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="[
        'الرواتب' => route('admin.payroll.index', ['period' => $payroll->period]),
        $payroll->employee->full_name => null,
    ]" />
@endsection

@section('content')
<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <x-ui.card title="تفاصيل الراتب">
            <x-slot:actions>
                <x-ui.status type="payroll" :value="$payroll->status" />
            </x-slot:actions>

            <x-ui.table>
                <tr>
                    <td class="px-3 py-2.5 text-ink-600">الراتب الأساسي</td>
                    <td class="px-3 py-2.5 text-end tabular-nums text-ink-900">{{ money($payroll->base_salary) }}</td>
                </tr>
                <tr>
                    <td class="px-3 py-2.5 text-ink-600">البدلات</td>
                    <td class="px-3 py-2.5 text-end tabular-nums text-ink-900">{{ money($payroll->allowances) }}</td>
                </tr>
                <tr>
                    <td class="px-3 py-2.5 text-ink-600">المكافآت</td>
                    <td class="px-3 py-2.5 text-end tabular-nums text-emerald-700">+ {{ money($payroll->bonuses) }}</td>
                </tr>
                <tr>
                    <td class="px-3 py-2.5 text-ink-600">الاستقطاعات</td>
                    <td class="px-3 py-2.5 text-end tabular-nums text-rose-700">− {{ money($payroll->deductions) }}</td>
                </tr>
                <tr>
                    <td class="px-3 py-2.5 text-ink-600">
                        أقساط السلف
                        @if ($payroll->advanceDeductions->isNotEmpty())
                            <span class="block text-xs text-ink-400">
                                {{ $payroll->advanceDeductions->count() }} قسط من سلف قائمة
                            </span>
                        @endif
                    </td>
                    <td class="px-3 py-2.5 text-end tabular-nums text-amber-700">− {{ money($payroll->advance_deductions) }}</td>
                </tr>

                <x-slot:footer>
                    <tr>
                        <td class="px-3 py-3.5 text-base text-ink-900">صافي الراتب</td>
                        <td class="px-3 py-3.5 text-end text-base tabular-nums text-ink-900">{{ money($payroll->net_salary) }}</td>
                    </tr>
                    <tr>
                        <td class="px-3 py-2.5 text-sm font-normal text-ink-600">المصروف حتى الآن</td>
                        <td class="px-3 py-2.5 text-end text-sm font-normal tabular-nums text-emerald-700">
                            {{ money($payroll->paid_amount) }}
                        </td>
                    </tr>
                    <tr>
                        <td class="px-3 py-2.5 text-sm font-normal text-ink-600">المتبقي</td>
                        <td class="px-3 py-2.5 text-end text-sm tabular-nums {{ $payroll->remainingAmount() > 0 ? 'text-amber-700' : 'text-ink-400' }}">
                            {{ money($payroll->remainingAmount()) }}
                        </td>
                    </tr>
                </x-slot:footer>
            </x-ui.table>
        </x-ui.card>

        @if ($payroll->payments->isNotEmpty())
            <x-ui.card padded="false" title="سجل الصرف">
                <x-ui.table :headers="['رقم الإيصال', 'التاريخ', 'الطريقة', ['label' => 'المبلغ', 'align' => 'end'], 'صرفها', '']">
                    @foreach ($payroll->payments as $payment)
                        <tr>
                            <td class="px-3 py-2.5 font-mono text-ink-700" dir="ltr">{{ $payment->receipt_number }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5">{{ $payment->paid_on->format('Y-m-d') }}</td>
                            <td class="px-3 py-2.5 text-ink-600">{{ $payment->paymentMethod?->label_ar }}</td>
                            <td class="px-3 py-2.5 text-end font-semibold tabular-nums">{{ money($payment->amount) }}</td>
                            <td class="px-3 py-2.5 text-xs text-ink-500">{{ $payment->payer?->name }}</td>
                            <td class="px-3 py-2.5 text-end">
                                <x-ui.button :href="route('admin.payroll.receipt', $payment)" variant="ghost" size="sm" icon="download" target="_blank" />
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif

        @if ($payroll->advanceDeductions->isNotEmpty())
            <x-ui.card padded="false" title="أقساط السلف المستقطعة">
                <x-ui.table :headers="['السلفة', 'تاريخ الصرف', ['label' => 'قيمة السلفة', 'align' => 'end'], ['label' => 'القسط', 'align' => 'end']]">
                    @foreach ($payroll->advanceDeductions as $deduction)
                        <tr>
                            <td class="px-3 py-2.5 font-mono text-xs text-ink-600" dir="ltr">#{{ $deduction->employee_advance_id }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5">{{ $deduction->advance?->granted_on?->format('Y-m-d') }}</td>
                            <td class="px-3 py-2.5 text-end tabular-nums">{{ money($deduction->advance?->amount) }}</td>
                            <td class="px-3 py-2.5 text-end tabular-nums text-amber-700">{{ money($deduction->amount) }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif
    </div>

    <div class="space-y-5">
        @can('pay', $payroll)
            <x-ui.card title="صرف الراتب">
                <form method="POST" action="{{ route('admin.payroll.pay', $payroll) }}" class="space-y-4">
                    @csrf
                    <x-form.input name="amount" type="number" step="0.01" min="0.01"
                                  :max="$payroll->remainingAmount()"
                                  :value="$payroll->remainingAmount()"
                                  label="المبلغ" required />
                    <x-form.input name="paid_on" type="date" label="تاريخ الصرف" :value="now()->toDateString()" required />
                    <x-form.select name="payment_method_id" label="طريقة الدفع" :options="$methods" placeholder="اختر الطريقة" required />
                    <x-form.input name="reference_number" label="رقم المرجع" />
                    <x-form.textarea name="notes" label="ملاحظات" rows="2" />

                    <x-ui.button type="submit" class="w-full">صرف الراتب</x-ui.button>
                </form>
            </x-ui.card>
        @endcan

        @can('update', $payroll)
            <x-ui.card title="إعادة الاحتساب">
                <form method="POST" action="{{ route('admin.payroll.recalculate', $payroll) }}" class="space-y-3">
                    @csrf
                    <x-form.input name="bonuses" type="number" step="0.01" min="0" label="المكافآت" :value="$payroll->bonuses" required />
                    <x-form.input name="deductions" type="number" step="0.01" min="0" label="الاستقطاعات" :value="$payroll->deductions" required />
                    <x-form.textarea name="notes" label="ملاحظات" rows="2" :value="$payroll->notes" />
                    <x-ui.button type="submit" variant="secondary" class="w-full">إعادة الاحتساب</x-ui.button>
                </form>
            </x-ui.card>
        @elseif ($payroll->isLocked())
            <x-ui.alert type="warning">
                لا يمكن إعادة احتساب راتب تم صرف جزء منه أو إلغاؤه.
            </x-ui.alert>
        @endcan

        <x-ui.card title="الموظف">
            <a href="{{ route('admin.employees.show', $payroll->employee) }}" class="text-sm font-medium text-brand-700 hover:underline">
                {{ $payroll->employee->full_name }}
            </a>
            <p class="mt-1 text-xs text-ink-400">{{ $payroll->employee->employee_number }}</p>
        </x-ui.card>
    </div>
</div>
@endsection
