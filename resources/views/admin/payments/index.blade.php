@extends('layouts.app')

@section('title', 'المدفوعات')
@section('subtitle', 'إجمالي المحصّل: ' . money($total))

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المدفوعات' => null]" />
@endsection

@section('content')
    <x-layout.page-header title="المدفوعات والإيرادات" description="سجل الدفعات المحصّلة من المتدربين.">
        <x-slot:actions>
            @canDo('payments.create')
                <x-ui.button :href="route('admin.payments.create')" icon="plus">تسجيل دفعة</x-ui.button>
            @endcanDo
            @canDo('reports.export')
                <x-ui.button :href="route('admin.reports.show', 'revenue')" variant="secondary" icon="file-text">تقرير الإيرادات</x-ui.button>
            @endcanDo
        </x-slot:actions>
    </x-layout.page-header>

    <x-ui.filters :action="route('admin.payments.index')" placeholder="ابحث برقم الإيصال أو اسم المتدرب…">
        <x-slot:fields>
            <x-form.select name="status" label="الحالة" :options="['completed' => 'مكتملة', 'voided' => 'ملغاة']" :selected="request('status')" placeholder="الكل" />
            <x-form.select name="payment_method_id" label="طريقة الدفع" :options="$methods" :selected="request('payment_method_id')" placeholder="الكل" />
            <x-form.input name="from" type="date" label="من تاريخ" :value="request('from')" />
            <x-form.input name="to" type="date" label="إلى تاريخ" :value="request('to')" />
        </x-slot:fields>
    </x-ui.filters>

    <x-ui.card padded="false">
        @if ($payments->isEmpty())
            <x-ui.empty-state icon="banknote" title="لا توجد دفعات مطابقة" />
        @else
            <x-ui.table :headers="['رقم الإيصال', 'التاريخ', 'المتدرب', 'طريقة الدفع', ['label' => 'المبلغ', 'align' => 'end'], 'استلمها', 'الحالة', ['label' => '', 'align' => 'end']]">
                @foreach ($payments as $payment)
                    <tr @class(['hover:bg-ink-50', 'opacity-60' => $payment->isVoided()])>
                        <td class="px-3 py-3 font-mono text-ink-800" dir="ltr">{{ $payment->receipt_number }}</td>
                        <td class="whitespace-nowrap px-3 py-3 text-ink-600">{{ $payment->paid_on->format('Y-m-d') }}</td>
                        <td class="px-3 py-3">
                            @if ($payment->trainee)
                                <a href="{{ route('admin.trainees.show', $payment->trainee) }}" class="text-ink-900 hover:text-brand-600">
                                    {{ $payment->trainee->full_name }}
                                </a>
                            @else
                                <span class="text-ink-400">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-ink-600">{{ $payment->paymentMethod?->label_ar }}</td>
                        <td class="px-3 py-3 text-end font-semibold tabular-nums {{ $payment->isVoided() ? 'text-ink-400 line-through' : 'text-ink-900' }}">
                            {{ money($payment->amount) }}
                        </td>
                        <td class="px-3 py-3 text-xs text-ink-500">{{ $payment->receiver?->name }}</td>
                        <td class="px-3 py-3"><x-ui.status type="payment" :value="$payment->status" /></td>
                        <td class="px-3 py-3 text-end">
                            <div class="flex justify-end gap-1">
                                <x-ui.button :href="route('admin.payments.show', $payment)" variant="ghost" size="sm">عرض</x-ui.button>
                                <x-ui.button :href="route('admin.payments.receipt', $payment)" variant="ghost" size="sm" icon="download" target="_blank" />
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $payments->links() }}</div>
@endsection
