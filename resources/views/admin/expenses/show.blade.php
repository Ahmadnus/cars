@extends('layouts.app')

@section('title', 'مصروف ' . $expense->reference)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المصاريف' => route('admin.expenses.index'), $expense->reference => null]" />
@endsection

@section('content')
<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <x-ui.card title="تفاصيل المصروف">
            <x-slot:actions>
                <x-ui.status type="expense" :value="$expense->status" />
            </x-slot:actions>

            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                @foreach ([
                    'المرجع' => $expense->reference,
                    'التاريخ' => arabic_date($expense->spent_on),
                    'التصنيف' => $expense->category?->name_ar,
                    'بند الأرباح' => $expense->category?->bucketLabel(),
                    'طريقة الدفع' => $expense->paymentMethod?->label_ar,
                    'المستفيد' => $expense->beneficiary ?: '—',
                    'رقم الفاتورة' => $expense->invoice_number ?: '—',
                    'سجّله' => $expense->creator?->name ?? '—',
                ] as $label => $value)
                    <div>
                        <dt class="text-xs text-ink-500">{{ $label }}</dt>
                        <dd class="mt-0.5 font-medium text-ink-900">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-5 rounded-xl bg-rose-50 p-4 text-center">
                <p class="text-xs text-rose-700">{{ $expense->title }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-rose-800">{{ money($expense->amount) }}</p>
            </div>

            @if ($expense->notes)
                <p class="mt-4 rounded-lg bg-ink-50 p-3 text-sm text-ink-600">{{ $expense->notes }}</p>
            @endif

            @if ($expense->isSystemGenerated())
                <x-ui.alert type="info" class="mt-4">
                    هذا المصروف أُنشئ تلقائياً من عملية أخرى في النظام (صرف راتب أو أجر مدرب أو فاتورة)،
                    ولا يمكن تعديله إلا من مصدره.
                </x-ui.alert>
            @endif

            @if ($expense->isCancelled())
                <x-ui.alert type="error" class="mt-4" title="مصروف ملغى">
                    {{ $expense->cancel_reason }}
                    <span class="mt-1 block text-xs opacity-75">
                        بواسطة {{ $expense->canceller?->name }} · {{ $expense->cancelled_at?->format('Y-m-d H:i') }}
                    </span>
                </x-ui.alert>
            @endif
        </x-ui.card>

        @if ($expense->documents->isNotEmpty())
            <x-ui.card padded="false" title="المرفقات">
                <x-ui.table :headers="['الملف', 'الحجم', 'رفعه', '']">
                    @foreach ($expense->documents as $document)
                        <tr>
                            <td class="px-3 py-2.5 text-ink-800">{{ $document->title }}</td>
                            <td class="px-3 py-2.5 text-xs text-ink-500" dir="ltr">{{ $document->humanSize() }}</td>
                            <td class="px-3 py-2.5 text-xs text-ink-500">{{ $document->uploader?->name }}</td>
                            <td class="px-3 py-2.5 text-end">
                                <x-ui.button :href="route('admin.documents.view', $document)" variant="ghost" size="sm" target="_blank">عرض</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif
    </div>

    <div class="space-y-5">
        <x-ui.card title="الإجراءات">
            <div class="flex flex-col gap-2">
                @can('update', $expense)
                    <x-ui.button :href="route('admin.expenses.edit', $expense)" variant="secondary" icon="edit">تعديل</x-ui.button>
                @endcan

                @can('cancel', $expense)
                    <x-ui.confirm
                        :action="route('admin.expenses.cancel', $expense)"
                        title="إلغاء المصروف"
                        message="سيتم عكس أثر المصروف على الصندوق مع الاحتفاظ بالسجل الأصلي."
                        confirm-label="إلغاء المصروف"
                        reason-label="سبب الإلغاء"
                    >
                        <x-slot:trigger>
                            <x-ui.button type="button" variant="danger" class="w-full">إلغاء المصروف</x-ui.button>
                        </x-slot:trigger>
                    </x-ui.confirm>
                @endcan

                @if (! auth()->user()->can('update', $expense) && ! auth()->user()->can('cancel', $expense))
                    <p class="text-sm text-ink-400">لا توجد إجراءات متاحة.</p>
                @endif
            </div>
        </x-ui.card>
    </div>
</div>
@endsection
