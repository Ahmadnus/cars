@extends('layouts.app')

@section('title', 'فواتير الخدمات')
@section('subtitle', 'غير مدفوع: ' . money($unpaidTotal))

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['فواتير الخدمات' => null]" />
@endsection

@section('content')
    <x-layout.page-header title="فواتير الخدمات" description="الكهرباء والمياه والإنترنت والهاتف والإيجار.">
        <x-slot:actions>
            <x-ui.button type="button" @click="$dispatch('open-modal', 'new-bill')" icon="plus">تسجيل فاتورة</x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    @if ($overdue > 0)
        <x-ui.alert type="error" class="mb-4" title="فواتير متأخرة">
            يوجد {{ $overdue }} فاتورة تجاوزت تاريخ الاستحقاق ولم تُدفع بعد.
        </x-ui.alert>
    @endif

    <x-ui.filters :action="route('admin.utilities.index')" :collapsible="false">
        <x-slot:fields>
            <x-form.select name="service_type" label="نوع الخدمة" :options="$serviceTypes" :selected="request('service_type')" placeholder="كل الخدمات" />
            <x-form.select name="status" label="الحالة" :options="['unpaid' => 'غير مدفوعة', 'paid' => 'مدفوعة']" :selected="request('status')" placeholder="الكل" />
            <x-form.input name="billing_month" label="شهر الفاتورة" :value="request('billing_month')" placeholder="YYYY-MM" dir="ltr" />
        </x-slot:fields>
    </x-ui.filters>

    <x-ui.card padded="false">
        @if ($bills->isEmpty())
            <x-ui.empty-state icon="zap" title="لا توجد فواتير مسجلة" />
        @else
            <x-ui.table :headers="['الخدمة', 'شهر الفاتورة', ['label' => 'المبلغ', 'align' => 'end'], 'الاستحقاق', 'تاريخ الدفع', 'رقم الفاتورة', 'الحالة', ['label' => '', 'align' => 'end']]">
                @foreach ($bills as $bill)
                    <tr class="hover:bg-ink-50">
                        <td class="px-3 py-3 font-medium text-ink-900">{{ $bill->serviceLabel() }}</td>
                        <td class="px-3 py-3 font-mono text-ink-600" dir="ltr">{{ $bill->billing_month }}</td>
                        <td class="px-3 py-3 text-end font-semibold tabular-nums">{{ money($bill->amount) }}</td>
                        <td class="whitespace-nowrap px-3 py-3">
                            <span @class(['text-rose-700 font-medium' => $bill->isOverdue(), 'text-ink-600' => ! $bill->isOverdue()])>
                                {{ $bill->due_date->format('Y-m-d') }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-ink-600">{{ $bill->paid_on?->format('Y-m-d') ?? '—' }}</td>
                        <td class="px-3 py-3 font-mono text-xs text-ink-500" dir="ltr">{{ $bill->invoice_number ?: '—' }}</td>
                        <td class="px-3 py-3">
                            <x-ui.status type="bill" :value="$bill->isOverdue() ? 'overdue' : $bill->status" />
                        </td>
                        <td class="px-3 py-3 text-end">
                            @unless ($bill->isPaid())
                                <x-ui.button type="button" size="sm" @click="$dispatch('open-modal', 'pay-{{ $bill->id }}')">دفع</x-ui.button>
                            @else
                                @if ($bill->expense)
                                    <x-ui.button :href="route('admin.expenses.show', $bill->expense)" variant="ghost" size="sm">المصروف</x-ui.button>
                                @endif
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $bills->links() }}</div>

    @foreach ($bills as $bill)
        @unless ($bill->isPaid())
            <x-ui.modal name="pay-{{ $bill->id }}" :title="'دفع فاتورة ' . $bill->serviceLabel()">
                <form method="POST" action="{{ route('admin.utilities.pay', $bill) }}" id="pay-form-{{ $bill->id }}" class="space-y-4">
                    @csrf
                    <dl class="space-y-1.5 rounded-lg bg-ink-50 p-3 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-500">المبلغ</dt>
                            <dd class="font-semibold tabular-nums text-ink-900">{{ money($bill->amount) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-500">شهر الفاتورة</dt>
                            <dd class="font-mono text-ink-800" dir="ltr">{{ $bill->billing_month }}</dd>
                        </div>
                    </dl>

                    <x-form.input name="paid_on" type="date" label="تاريخ الدفع" :value="now()->toDateString()" required />
                    <x-form.select name="payment_method_id" label="طريقة الدفع" :options="$methods" placeholder="اختر الطريقة" required />

                    <x-ui.alert type="info">سيتم تسجيل الفاتورة كمصروف ضمن بند الخدمات.</x-ui.alert>
                </form>

                <x-slot:footer>
                    <x-ui.button type="button" variant="secondary" size="sm" @click="open = false">إلغاء</x-ui.button>
                    <x-ui.button type="submit" size="sm" form="pay-form-{{ $bill->id }}">تأكيد الدفع</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endunless
    @endforeach

    <x-ui.modal name="new-bill" title="تسجيل فاتورة خدمات">
        <form method="POST" action="{{ route('admin.utilities.store') }}" id="new-bill-form"
              enctype="multipart/form-data" class="space-y-4">
            @csrf
            <x-form.select name="service_type" label="نوع الخدمة" :options="$serviceTypes" placeholder="اختر الخدمة" required />
            <x-form.input name="billing_month" label="شهر الفاتورة" :value="now()->format('Y-m')" required dir="ltr"
                          placeholder="YYYY-MM" hint="فاتورة واحدة لكل خدمة في الشهر الواحد." />
            <x-form.input name="amount" type="number" step="0.01" min="0.01" label="المبلغ" required />
            <x-form.input name="due_date" type="date" label="تاريخ الاستحقاق" required />
            <x-form.input name="invoice_number" label="رقم الفاتورة" dir="ltr" />
            <x-form.textarea name="notes" label="ملاحظات" rows="2" />

            <x-form.field label="مرفق الفاتورة" name="attachment">
                <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf"
                       class="block w-full text-sm text-ink-600 file:me-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700">
            </x-form.field>
        </form>

        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" size="sm" @click="open = false">إلغاء</x-ui.button>
            <x-ui.button type="submit" size="sm" form="new-bill-form">تسجيل</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endsection
