@extends('layouts.app')

@section('title', 'أجور المدربين')
@section('subtitle', $period)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['أجور المدربين' => null]" />
@endsection

@section('content')
    <x-layout.page-header title="أجور المدربين" description="كشوف الأجور الشهرية محسوبة من الحصص المنجزة وقواعد الأجر.">
        <x-slot:actions>
            <form method="GET" action="{{ route('admin.trainer-compensation.index') }}">
                <select name="period" onchange="this.form.submit()"
                        class="rounded-lg border-ink-300 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                    @foreach ($periods as $value => $label)
                        <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>

            <x-ui.button :href="route('admin.trainer-compensation.rules')" variant="secondary" icon="settings">قواعد الأجور</x-ui.button>

            @canDo('trainer_compensation.manage')
                <form method="POST" action="{{ route('admin.trainer-compensation.calculate') }}">
                    @csrf
                    <input type="hidden" name="period" value="{{ $period }}">
                    <x-ui.button type="submit" icon="plus">احتساب أجور الشهر</x-ui.button>
                </form>
            @endcanDo
        </x-slot:actions>
    </x-layout.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ui.stat-card label="إجمالي الحصص" :value="$totals['lessons']" icon="steering" tone="neutral" />
        <x-ui.stat-card label="إجمالي الأجور" :value="money($totals['net'])" icon="wallet" tone="brand" />
        <x-ui.stat-card label="المصروف" :value="money($totals['paid'])" icon="check" tone="success" />
        <x-ui.stat-card label="المتبقي" :value="money($totals['remaining'])" icon="alert" :tone="$totals['remaining'] > 0 ? 'warning' : 'success'" />
    </div>

    <x-ui.card padded="false">
        @if ($records->isEmpty())
            <x-ui.empty-state
                icon="wallet"
                title="لا توجد كشوف أجور لهذا الشهر"
                description="اضغط «احتساب أجور الشهر» لاحتساب أجور جميع المدربين النشطين."
            />
        @else
            <x-ui.table :headers="[
                'المدرب', 'نموذج الأجر',
                ['label' => 'الحصص', 'align' => 'center'],
                ['label' => 'ساعات', 'align' => 'center'],
                ['label' => 'الإيراد المنسوب', 'align' => 'end'],
                ['label' => 'الإجمالي', 'align' => 'end'],
                ['label' => 'الصافي', 'align' => 'end'],
                ['label' => 'المصروف', 'align' => 'end'],
                'الحالة',
                ['label' => '', 'align' => 'end'],
            ]">
                @foreach ($records as $record)
                    <tr class="hover:bg-ink-50">
                        <td class="px-3 py-3 font-medium text-ink-900">{{ $record->trainer->full_name }}</td>
                        <td class="px-3 py-3 text-xs text-ink-500">
                            {{ \App\Models\TrainerCompensationRule::MODELS[$record->model] ?? $record->model }}
                        </td>
                        <td class="px-3 py-3 text-center tabular-nums">{{ $record->lessons_count }}</td>
                        <td class="px-3 py-3 text-center tabular-nums text-ink-600">{{ $record->trainingHours() }}</td>
                        <td class="px-3 py-3 text-end tabular-nums text-ink-600">{{ money($record->attributed_revenue, false) }}</td>
                        <td class="px-3 py-3 text-end tabular-nums">{{ money($record->gross_amount, false) }}</td>
                        <td class="px-3 py-3 text-end font-semibold tabular-nums text-ink-900">{{ money($record->net_amount, false) }}</td>
                        <td class="px-3 py-3 text-end tabular-nums text-emerald-700">{{ money($record->paid_amount, false) }}</td>
                        <td class="px-3 py-3"><x-ui.status type="compensation" :value="$record->status" /></td>
                        <td class="px-3 py-3 text-end">
                            <x-ui.button :href="route('admin.trainer-compensation.show', $record)" variant="ghost" size="sm">تفاصيل</x-ui.button>
                        </td>
                    </tr>
                @endforeach

                <x-slot:footer>
                    <tr>
                        <td colspan="6" class="px-3 py-3 text-ink-700">الإجمالي</td>
                        <td class="px-3 py-3 text-end tabular-nums text-ink-900">{{ money($totals['net'], false) }}</td>
                        <td class="px-3 py-3 text-end tabular-nums text-emerald-700">{{ money($totals['paid'], false) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </x-slot:footer>
            </x-ui.table>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $records->links() }}</div>
@endsection
