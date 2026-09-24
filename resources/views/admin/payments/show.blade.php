@extends('layouts.app')

@section('title', 'إيصال رقم ' . $payment->receipt_number)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المدفوعات' => route('admin.payments.index'), $payment->receipt_number => null]" />
@endsection

@section('content')
<div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <x-ui.card title="تفاصيل الدفعة">
            <x-slot:actions>
                <x-ui.status type="payment" :value="$payment->status" />
            </x-slot:actions>

            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                @foreach ([
                    'رقم الإيصال' => $payment->receipt_number,
                    'تاريخ الدفع' => arabic_date($payment->paid_on),
                    'المتدرب' => $payment->trainee?->full_name ?? '—',
                    'الباقة' => $payment->traineePackage?->package_name ?? '—',
                    'طريقة الدفع' => $payment->paymentMethod?->label_ar,
                    'رقم المرجع' => $payment->reference_number ?: '—',
                    'استلمها' => $payment->receiver?->name ?? '—',
                    'الفرع' => $payment->branch?->name,
                ] as $label => $value)
                    <div>
                        <dt class="text-xs text-ink-500">{{ $label }}</dt>
                        <dd class="mt-0.5 font-medium text-ink-900">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-5 rounded-xl bg-brand-50 p-4 text-center">
                <p class="text-xs text-brand-700">المبلغ المدفوع</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-brand-800">{{ money($payment->amount) }}</p>
            </div>

            @if ($payment->notes)
                <p class="mt-4 rounded-lg bg-ink-50 p-3 text-sm text-ink-600">{{ $payment->notes }}</p>
            @endif

            @if ($payment->isVoided())
                <x-ui.alert type="error" class="mt-4" title="دفعة ملغاة">
                    {{ $payment->void_reason }}
                    <span class="mt-1 block text-xs opacity-75">
                        بواسطة {{ $payment->voider?->name }} · {{ $payment->voided_at?->format('Y-m-d H:i') }}
                    </span>
                </x-ui.alert>
            @endif
        </x-ui.card>
    </div>

    <div class="space-y-5">
        <x-ui.card title="الإجراءات">
            <div class="flex flex-col gap-2">
                <x-ui.button :href="route('admin.payments.receipt', $payment)" target="_blank" icon="download">
                    طباعة الإيصال (PDF)
                </x-ui.button>

                @can('void', $payment)
                    <x-ui.confirm
                        :action="route('admin.payments.void', $payment)"
                        title="إلغاء الدفعة"
                        message="سيتم عكس أثر الدفعة على الصندوق وعلى رصيد المتدرب. لن يتم حذف السجل، ويبقى رقم الإيصال محفوظاً."
                        confirm-label="إلغاء الدفعة"
                        reason-label="سبب الإلغاء"
                    >
                        <x-slot:trigger>
                            <x-ui.button type="button" variant="danger" class="w-full">إلغاء الدفعة</x-ui.button>
                        </x-slot:trigger>
                    </x-ui.confirm>
                @endcan

                @if ($payment->trainee)
                    <x-ui.button :href="route('admin.trainees.show', $payment->trainee)" variant="secondary">
                        ملف المتدرب
                    </x-ui.button>
                @endif
            </div>
        </x-ui.card>

        <x-ui.alert type="info" title="سياسة السجلات المالية">
            لا تُحذف الدفعات نهائياً. الإلغاء يعكس الأثر المالي مع الاحتفاظ بالسجل الأصلي لأغراض التدقيق.
        </x-ui.alert>
    </div>
</div>
@endsection
