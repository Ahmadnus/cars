@extends('layouts.app')

@section('title', 'سلف الموظفين')
@section('subtitle', 'قائم: ' . money($outstanding))

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['السلف' => null]" />
@endsection

@section('content')
    <x-layout.page-header
        title="سلف الموظفين"
        description="تُستقطع الأقساط تلقائياً من كشف الراتب الشهري، دون تجاوز المبلغ المتبقي."
    >
        <x-slot:actions>
            <x-ui.button type="button" @click="$dispatch('open-modal', 'new-advance')" icon="plus">صرف سلفة</x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <x-ui.filters :action="route('admin.advances.index')" :collapsible="false">
        <x-slot:fields>
            <x-form.select name="employee_id" label="الموظف" :options="$employees" :selected="request('employee_id')" placeholder="كل الموظفين" />
            <x-form.select name="status" label="الحالة" :options="$statuses" :selected="request('status')" placeholder="كل الحالات" />
        </x-slot:fields>
    </x-ui.filters>

    <x-ui.card padded="false">
        @if ($advances->isEmpty())
            <x-ui.empty-state icon="hand-coins" title="لا توجد سلف مسجلة" />
        @else
            <x-ui.table :headers="[
                'الموظف', 'تاريخ الصرف',
                ['label' => 'القيمة', 'align' => 'end'],
                ['label' => 'القسط الشهري', 'align' => 'end'],
                ['label' => 'المسدد', 'align' => 'end'],
                ['label' => 'المتبقي', 'align' => 'end'],
                'نسبة السداد', 'الحالة',
            ]">
                @foreach ($advances as $advance)
                    <tr class="hover:bg-ink-50">
                        <td class="px-3 py-3">
                            <a href="{{ route('admin.advances.show', $advance) }}" class="font-medium text-ink-900 hover:text-brand-600">
                                {{ $advance->employee->full_name }}
                            </a>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-ink-600">{{ $advance->granted_on->format('Y-m-d') }}</td>
                        <td class="px-3 py-3 text-end tabular-nums">{{ money($advance->amount, false) }}</td>
                        <td class="px-3 py-3 text-end tabular-nums text-ink-600">{{ money($advance->installment_amount, false) }}</td>
                        <td class="px-3 py-3 text-end tabular-nums text-emerald-700">{{ money($advance->deducted_amount, false) }}</td>
                        <td class="px-3 py-3 text-end font-semibold tabular-nums text-amber-700">{{ money($advance->remaining_amount, false) }}</td>
                        <td class="px-3 py-3">
                            <span class="flex items-center gap-2">
                                <span class="h-1.5 w-16 overflow-hidden rounded-full bg-ink-100">
                                    <span class="block h-full rounded-full bg-emerald-500" style="width: {{ $advance->repaidPercent() }}%"></span>
                                </span>
                                <span class="text-xs tabular-nums text-ink-500">{{ $advance->repaidPercent() }}%</span>
                            </span>
                        </td>
                        <td class="px-3 py-3">
                            <x-ui.badge :tone="$advance->status === 'settled' ? 'success' : ($advance->status === 'cancelled' ? 'neutral' : 'warning')">
                                {{ $statuses[$advance->status] ?? $advance->status }}
                            </x-ui.badge>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $advances->links() }}</div>

    <x-ui.modal name="new-advance" title="صرف سلفة لموظف">
        <form method="POST" action="{{ route('admin.advances.store') }}" id="new-advance-form" class="space-y-4">
            @csrf
            <x-form.select name="employee_id" label="الموظف" :options="$employees" placeholder="اختر الموظف" required />
            <x-form.input name="amount" type="number" step="0.01" min="0.01" label="قيمة السلفة" required />
            <x-form.input name="granted_on" type="date" label="تاريخ الصرف" :value="now()->toDateString()" required />
            <x-form.input name="installments_count" type="number" min="1" max="36" value="3" label="عدد الأقساط" required
                          hint="يُقسَّم المبلغ على هذا العدد ويُستقطع شهرياً من الراتب." />
            <x-form.select name="payment_method_id" label="طريقة الصرف" :options="$methods" placeholder="اختر الطريقة" required />
            <x-form.textarea name="notes" label="ملاحظات" rows="2" />

            <x-ui.alert type="info">
                سيتم تسجيل السلفة كمصروف وخصمها من الصندوق إذا كان الصرف نقدياً.
            </x-ui.alert>
        </form>

        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" size="sm" @click="open = false">إلغاء</x-ui.button>
            <x-ui.button type="submit" size="sm" form="new-advance-form">صرف السلفة</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endsection
