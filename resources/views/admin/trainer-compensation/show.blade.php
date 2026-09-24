@extends('layouts.app')

@section('title', 'كشف أجر مدرب')
@section('subtitle', $record->trainer->full_name . ' — ' . $record->period)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="[
        'أجور المدربين' => route('admin.trainer-compensation.index', ['period' => $record->period]),
        $record->trainer->full_name => null,
    ]" />
@endsection

@section('content')
<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <x-ui.card title="تفصيل الأجر">
            <x-slot:actions>
                <x-ui.status type="compensation" :value="$record->status" />
            </x-slot:actions>

            <div class="mb-5 grid grid-cols-3 gap-3">
                <div class="rounded-xl bg-ink-50 p-3 text-center">
                    <p class="text-xs text-ink-500">حصص منجزة</p>
                    <p class="mt-1 text-lg font-bold tabular-nums text-ink-900">{{ $record->lessons_count }}</p>
                </div>
                <div class="rounded-xl bg-ink-50 p-3 text-center">
                    <p class="text-xs text-ink-500">ساعات التدريب</p>
                    <p class="mt-1 text-lg font-bold tabular-nums text-ink-900">{{ $record->trainingHours() }}</p>
                </div>
                <div class="rounded-xl bg-ink-50 p-3 text-center">
                    <p class="text-xs text-ink-500">الإيراد المنسوب</p>
                    <p class="mt-1 text-lg font-bold tabular-nums text-ink-900">{{ money($record->attributed_revenue, false) }}</p>
                </div>
            </div>

            <x-ui.table>
                <tr>
                    <td class="px-3 py-2.5 text-ink-600">
                        نموذج الأجر
                        <span class="block text-xs text-ink-400">{{ \App\Models\TrainerCompensationRule::MODELS[$record->model] ?? $record->model }}</span>
                    </td>
                    <td class="px-3 py-2.5 text-end text-ink-400">—</td>
                </tr>
                <tr>
                    <td class="px-3 py-2.5 text-ink-600">الراتب الأساسي</td>
                    <td class="px-3 py-2.5 text-end tabular-nums text-ink-900">{{ money($record->base_salary) }}</td>
                </tr>
                <tr>
                    <td class="px-3 py-2.5 text-ink-600">
                        أجر الحصص
                        @if ($record->lessons_count > 0 && (float) $record->lesson_earnings > 0)
                            <span class="block text-xs text-ink-400">
                                {{ $record->lessons_count }} حصة × {{ money($record->rule?->per_lesson_rate) }}
                            </span>
                        @endif
                    </td>
                    <td class="px-3 py-2.5 text-end tabular-nums text-ink-900">{{ money($record->lesson_earnings) }}</td>
                </tr>
                <tr>
                    <td class="px-3 py-2.5 text-ink-600">
                        نسبة من الإيراد
                        @if ((float) $record->percentage_earnings > 0)
                            <span class="block text-xs text-ink-400">
                                {{ percent($record->rule?->revenue_percentage, 0) }} من {{ money($record->attributed_revenue) }}
                            </span>
                        @endif
                    </td>
                    <td class="px-3 py-2.5 text-end tabular-nums text-ink-900">{{ money($record->percentage_earnings) }}</td>
                </tr>
                <tr>
                    <td class="px-3 py-2.5 text-ink-600">المكافآت</td>
                    <td class="px-3 py-2.5 text-end tabular-nums text-emerald-700">+ {{ money($record->bonuses) }}</td>
                </tr>
                <tr>
                    <td class="px-3 py-2.5 text-ink-600">الاستقطاعات</td>
                    <td class="px-3 py-2.5 text-end tabular-nums text-rose-700">− {{ money($record->deductions) }}</td>
                </tr>

                <x-slot:footer>
                    <tr>
                        <td class="px-3 py-3.5 text-base text-ink-900">صافي الأجر</td>
                        <td class="px-3 py-3.5 text-end text-base tabular-nums text-ink-900">{{ money($record->net_amount) }}</td>
                    </tr>
                    <tr>
                        <td class="px-3 py-2.5 text-sm font-normal text-ink-600">المصروف</td>
                        <td class="px-3 py-2.5 text-end text-sm font-normal tabular-nums text-emerald-700">{{ money($record->paid_amount) }}</td>
                    </tr>
                    <tr>
                        <td class="px-3 py-2.5 text-sm font-normal text-ink-600">المتبقي</td>
                        <td class="px-3 py-2.5 text-end text-sm tabular-nums {{ $record->remainingAmount() > 0 ? 'text-amber-700' : 'text-ink-400' }}">
                            {{ money($record->remainingAmount()) }}
                        </td>
                    </tr>
                </x-slot:footer>
            </x-ui.table>
        </x-ui.card>

        @if ($record->payments->isNotEmpty())
            <x-ui.card padded="false" title="سجل الصرف">
                <x-ui.table :headers="['رقم الإيصال', 'التاريخ', 'الطريقة', ['label' => 'المبلغ', 'align' => 'end'], 'صرفها', '']">
                    @foreach ($record->payments as $payment)
                        <tr>
                            <td class="px-3 py-2.5 font-mono text-ink-700" dir="ltr">{{ $payment->receipt_number }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5">{{ $payment->paid_on->format('Y-m-d') }}</td>
                            <td class="px-3 py-2.5 text-ink-600">{{ $payment->paymentMethod?->label_ar }}</td>
                            <td class="px-3 py-2.5 text-end font-semibold tabular-nums">{{ money($payment->amount) }}</td>
                            <td class="px-3 py-2.5 text-xs text-ink-500">{{ $payment->payer?->name }}</td>
                            <td class="px-3 py-2.5 text-end">
                                <x-ui.button :href="route('admin.trainer-compensation.receipt', $payment)"
                                             variant="ghost" size="sm" icon="download" target="_blank" />
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif
    </div>

    <div class="space-y-5">
        @can('approve', $record)
            <x-ui.card title="الاعتماد">
                <p class="mb-3 text-sm text-ink-500">
                    يجب اعتماد الكشف قبل الصرف. بعد الاعتماد لا يمكن إعادة احتسابه.
                </p>
                <form method="POST" action="{{ route('admin.trainer-compensation.approve', $record) }}">
                    @csrf
                    <x-ui.button type="submit" class="w-full">اعتماد الكشف</x-ui.button>
                </form>
            </x-ui.card>
        @endcan

        @can('pay', $record)
            <x-ui.card title="صرف الأجر">
                <form method="POST" action="{{ route('admin.trainer-compensation.pay', $record) }}" class="space-y-4">
                    @csrf
                    <x-form.input name="amount" type="number" step="0.01" min="0.01"
                                  :max="$record->remainingAmount()" :value="$record->remainingAmount()"
                                  label="المبلغ" required />
                    <x-form.input name="paid_on" type="date" label="تاريخ الصرف" :value="now()->toDateString()" required />
                    <x-form.select name="payment_method_id" label="طريقة الدفع" :options="$methods" placeholder="اختر الطريقة" required />
                    <x-form.input name="reference_number" label="رقم المرجع" />
                    <x-form.textarea name="notes" label="ملاحظات" rows="2" />

                    <x-ui.button type="submit" class="w-full">صرف الأجر</x-ui.button>
                </form>
            </x-ui.card>
        @endcan

        <x-ui.card title="المدرب">
            <a href="{{ route('admin.trainers.show', $record->trainer) }}" class="text-sm font-medium text-brand-700 hover:underline">
                {{ $record->trainer->full_name }}
            </a>
            <p class="mt-1 text-xs text-ink-400">{{ $record->trainer->trainer_number }}</p>
        </x-ui.card>

        <x-ui.alert type="info">
            تُحتسب كل الأرقام على الخادم من الحصص المنجزة فعلياً وقاعدة الأجر السارية في تلك الفترة.
        </x-ui.alert>
    </div>
</div>
@endsection
