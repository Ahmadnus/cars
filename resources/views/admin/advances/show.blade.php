@extends('layouts.app')

@section('title', 'تفاصيل السلفة')
@section('subtitle', $advance->employee->full_name)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['السلف' => route('admin.advances.index'), $advance->employee->full_name => null]" />
@endsection

@section('content')
<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <x-ui.card title="بيانات السلفة">
            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                @foreach ([
                    'الموظف' => $advance->employee->full_name,
                    'تاريخ الصرف' => arabic_date($advance->granted_on),
                    'طريقة الصرف' => $advance->paymentMethod?->label_ar ?? '—',
                    'عدد الأقساط' => $advance->installments_count,
                    'القسط الشهري' => money($advance->installment_amount),
                    'صرفها' => $advance->creator?->name ?? '—',
                ] as $label => $value)
                    <div>
                        <dt class="text-xs text-ink-500">{{ $label }}</dt>
                        <dd class="mt-0.5 font-medium text-ink-900">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-5 grid grid-cols-3 gap-3">
                <div class="rounded-xl bg-ink-50 p-3 text-center">
                    <p class="text-xs text-ink-500">قيمة السلفة</p>
                    <p class="mt-1 font-bold tabular-nums text-ink-900">{{ money($advance->amount) }}</p>
                </div>
                <div class="rounded-xl bg-emerald-50 p-3 text-center">
                    <p class="text-xs text-emerald-700">المسدد</p>
                    <p class="mt-1 font-bold tabular-nums text-emerald-800">{{ money($advance->deducted_amount) }}</p>
                </div>
                <div class="rounded-xl bg-amber-50 p-3 text-center">
                    <p class="text-xs text-amber-700">المتبقي</p>
                    <p class="mt-1 font-bold tabular-nums text-amber-800">{{ money($advance->remaining_amount) }}</p>
                </div>
            </div>

            @if ($advance->notes)
                <p class="mt-4 rounded-lg bg-ink-50 p-3 text-sm text-ink-600">{{ $advance->notes }}</p>
            @endif
        </x-ui.card>

        <x-ui.card padded="false" title="الأقساط المستقطعة">
            @if ($advance->deductions->isEmpty())
                <x-ui.empty-state icon="receipt" title="لم يُستقطع أي قسط بعد"
                                  description="سيُستقطع أول قسط عند إنشاء كشف الراتب القادم." />
            @else
                <x-ui.table :headers="['الشهر', ['label' => 'القسط', 'align' => 'end'], 'حالة الكشف', '']">
                    @foreach ($advance->deductions as $deduction)
                        <tr>
                            <td class="px-3 py-2.5">{{ $deduction->payroll?->period }}</td>
                            <td class="px-3 py-2.5 text-end tabular-nums text-amber-700">{{ money($deduction->amount) }}</td>
                            <td class="px-3 py-2.5">
                                @if ($deduction->payroll)
                                    <x-ui.status type="payroll" :value="$deduction->payroll->status" />
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-end">
                                @if ($deduction->payroll)
                                    <x-ui.button :href="route('admin.payroll.show', $deduction->payroll)" variant="ghost" size="sm">
                                        الكشف
                                    </x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    </div>

    <div class="space-y-5">
        <x-ui.card title="نسبة السداد">
            <div class="flex items-center gap-4">
                <div class="relative size-20 shrink-0">
                    <svg viewBox="0 0 36 36" class="size-full -rotate-90">
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="#eef0f1" stroke-width="3.5" />
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="#059669" stroke-width="3.5"
                                stroke-linecap="round" stroke-dasharray="{{ $advance->repaidPercent() }} 100" />
                    </svg>
                    <span class="absolute inset-0 flex items-center justify-center text-sm font-semibold tabular-nums text-ink-900">
                        {{ $advance->repaidPercent() }}%
                    </span>
                </div>
                <p class="text-sm text-ink-500">
                    {{ $advance->deductions->count() }} من {{ $advance->installments_count }} قسط.
                </p>
            </div>
        </x-ui.card>

        <x-ui.alert type="info">
            لا يمكن أن يتجاوز مجموع الأقساط قيمة السلفة — يتحقق النظام من ذلك عند كل احتساب راتب.
        </x-ui.alert>
    </div>
</div>
@endsection
