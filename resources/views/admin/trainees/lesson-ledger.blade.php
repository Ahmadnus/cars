@extends('layouts.app')

@section('title', 'سجل رصيد الحصص')
@section('subtitle', $enrolment->trainee->full_name . ' — ' . $enrolment->package_name)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="[
        'المتدربون' => route('admin.trainees.index'),
        $enrolment->trainee->full_name => route('admin.trainees.show', $enrolment->trainee),
        'سجل الحصص' => null,
    ]" />
@endsection

@section('content')
@php
    $typeLabels = [
        'package_credit' => 'رصيد باقة',
        'extra_credit' => 'حصص إضافية',
        'manual_credit' => 'إضافة يدوية',
        'consumption' => 'حصة منجزة',
        'no_show' => 'عدم حضور',
        'late_cancellation' => 'إلغاء متأخر',
        'refund' => 'استرجاع',
        'manual_debit' => 'خصم يدوي',
    ];
@endphp

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <x-ui.stat-card label="إجمالي المضاف" :value="$summary['credited']" icon="plus" tone="info" />
    <x-ui.stat-card label="إجمالي المخصوم" :value="$summary['consumed']" icon="trending-down" tone="warning" />
    <x-ui.stat-card label="حصص منجزة" :value="$summary['completed']" icon="check" tone="success" />
    <x-ui.stat-card
        label="الرصيد المتبقي"
        :value="$summary['remaining']"
        icon="package"
        :tone="$summary['remaining'] > 2 ? 'brand' : 'danger'"
    />
</div>

<x-ui.card padded="false" title="حركات الرصيد" subtitle="سجل غير قابل للتعديل — كل حركة تُضاف ولا تُعدَّل.">
    @if ($transactions->isEmpty())
        <x-ui.empty-state icon="file-text" title="لا توجد حركات" />
    @else
        <x-ui.table :headers="['التاريخ', 'نوع الحركة', 'البيان', ['label' => 'الكمية', 'align' => 'center'], 'سجّلها']">
            @foreach ($transactions as $transaction)
                <tr>
                    <td class="whitespace-nowrap px-3 py-2.5 text-ink-600">{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                    <td class="px-3 py-2.5">
                        <x-ui.badge :tone="$transaction->isCredit() ? 'success' : 'warning'">
                            {{ $typeLabels[$transaction->type] ?? $transaction->type }}
                        </x-ui.badge>
                    </td>
                    <td class="px-3 py-2.5 text-ink-700">{{ $transaction->description ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-center font-semibold tabular-nums {{ $transaction->isCredit() ? 'text-emerald-700' : 'text-rose-700' }}">
                        {{ $transaction->quantity > 0 ? '+' : '' }}{{ $transaction->quantity }}
                    </td>
                    <td class="px-3 py-2.5 text-xs text-ink-500">{{ $transaction->creator?->name ?? 'النظام' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif
</x-ui.card>

<div class="mt-4">{{ $transactions->links() }}</div>

<x-ui.alert type="info" class="mt-5" title="كيف يُحسب الرصيد">
    الرصيد المتبقي = مجموع كل الحركات أعلاه. لا يُخزَّن الرصيد كرقم ثابت، ما يجعله قابلاً لإعادة البناء
    والتدقيق في أي وقت.
</x-ui.alert>
@endsection
