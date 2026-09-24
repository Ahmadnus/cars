@extends('layouts.app')

@section('title', 'الأرباح والخسائر')
@section('subtitle', $from->format('Y-m-d') . ' — ' . $to->format('Y-m-d'))

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الأرباح والخسائر' => null]" />
@endsection

@section('content')
    <x-layout.page-header
        title="بيان الأرباح والخسائر"
        description="محسوب من الدفعات والمصاريف الفعلية المسجلة في النظام."
    />

    {{-- Period picker --}}
    <form method="GET" action="{{ route('admin.profit.index') }}"
          x-data="{ preset: '{{ $preset }}' }"
          class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-ink-200 bg-white p-3">

        <div class="flex rounded-lg border border-ink-200 p-0.5">
            @foreach (['today' => 'اليوم', 'week' => 'الأسبوع', 'month' => 'الشهر', 'quarter' => 'الربع', 'year' => 'السنة', 'custom' => 'مخصص'] as $key => $label)
                <button type="submit" name="preset" value="{{ $key }}"
                        @class([
                            'rounded-md px-3 py-1.5 text-sm transition',
                            'bg-brand-600 font-medium text-white' => $preset === $key,
                            'text-ink-600 hover:bg-ink-100' => $preset !== $key,
                        ])>{{ $label }}</button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-end gap-2" x-show="preset === 'custom'" x-cloak>
            <x-form.input name="from" type="date" label="من" :value="$from->format('Y-m-d')" />
            <x-form.input name="to" type="date" label="إلى" :value="$to->format('Y-m-d')" />
            <input type="hidden" name="preset" value="custom">
            <x-ui.button type="submit" size="md">تطبيق</x-ui.button>
        </div>
    </form>

    {{-- Headline figures --}}
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ui.stat-card label="إجمالي الإيرادات" :value="money($summary['revenue'])" icon="banknote" tone="success" />
        <x-ui.stat-card label="إجمالي المصاريف" :value="money($summary['expenses'])" icon="trending-down" tone="danger" />
        <x-ui.stat-card
            label="صافي الربح"
            :value="money($summary['net_profit'])"
            icon="wallet"
            :tone="$summary['net_profit'] >= 0 ? 'success' : 'danger'"
        />
        <x-ui.stat-card
            label="هامش الربح"
            :value="percent($summary['margin'])"
            icon="chart"
            :tone="$summary['margin'] >= 0 ? 'brand' : 'danger'"
        />
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-ui.card title="الإيرادات مقابل المصاريف">
                <x-ui.chart :height="280" :spec="[
                    'type' => 'bar',
                    'data' => [
                        'labels' => collect($series)->pluck($seriesKey)->map(fn ($v) => $seriesKey === 'date' ? substr($v, 5) : $v)->all(),
                        'datasets' => [
                            ['label' => 'الإيرادات', 'data' => collect($series)->pluck('revenue')->all()],
                            ['label' => 'المصاريف', 'data' => collect($series)->pluck('expenses')->all()],
                        ],
                    ],
                ]" />
            </x-ui.card>

            {{-- The P&L statement proper --}}
            <x-ui.card padded="false" title="بيان الأرباح والخسائر">
                <x-ui.table>
                    <tr class="bg-emerald-50/50">
                        <td class="px-4 py-3 font-semibold text-ink-900">إجمالي الإيرادات</td>
                        <td class="px-4 py-3 text-end font-semibold tabular-nums text-emerald-700">
                            {{ money($summary['revenue']) }}
                        </td>
                        <td class="w-20 px-4 py-3 text-end text-xs text-ink-400">100%</td>
                    </tr>

                    <tr>
                        <td colspan="3" class="bg-ink-50 px-4 py-2 text-xs font-medium text-ink-500">يُخصم منها</td>
                    </tr>

                    @foreach ($summary['buckets'] as $bucket)
                        <tr @class(['text-ink-300' => $bucket['amount'] == 0])>
                            <td class="px-4 py-2.5 ps-8">{{ $bucket['label'] }}</td>
                            <td class="px-4 py-2.5 text-end tabular-nums">{{ money($bucket['amount']) }}</td>
                            <td class="px-4 py-2.5 text-end text-xs text-ink-400">{{ percent($bucket['percent'], 0) }}</td>
                        </tr>
                    @endforeach

                    <tr class="border-t-2 border-ink-200 bg-rose-50/40">
                        <td class="px-4 py-3 font-semibold text-ink-900">إجمالي المصاريف</td>
                        <td class="px-4 py-3 text-end font-semibold tabular-nums text-rose-700">
                            ({{ money($summary['expenses']) }})
                        </td>
                        <td class="px-4 py-3"></td>
                    </tr>

                    <tr class="border-t-2 border-ink-300 bg-ink-50">
                        <td class="px-4 py-4 text-base font-bold text-ink-900">صافي الربح</td>
                        <td class="px-4 py-4 text-end text-base font-bold tabular-nums {{ $summary['net_profit'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                            {{ money($summary['net_profit']) }}
                        </td>
                        <td class="px-4 py-4 text-end text-xs text-ink-500">{{ percent($summary['margin']) }}</td>
                    </tr>
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="space-y-5">
            @if (count($categories))
                <x-ui.card title="المصاريف حسب التصنيف">
                    <x-ui.chart :height="220" :spec="[
                        'type' => 'doughnut',
                        'data' => [
                            'labels' => collect($categories)->take(7)->pluck('category')->all(),
                            'datasets' => [
                                ['data' => collect($categories)->take(7)->pluck('amount')->all()],
                            ],
                        ],
                    ]" />
                </x-ui.card>

                <x-ui.card padded="false" title="تفصيل التصنيفات">
                    <x-ui.table :headers="['التصنيف', ['label' => 'المبلغ', 'align' => 'end'], ['label' => '%', 'align' => 'end']]">
                        @foreach ($categories as $row)
                            <tr>
                                <td class="px-3 py-2.5 text-ink-700">{{ $row['category'] }}</td>
                                <td class="px-3 py-2.5 text-end tabular-nums">{{ money($row['amount'], false) }}</td>
                                <td class="px-3 py-2.5 text-end text-xs text-ink-400">{{ percent($row['percent'], 0) }}</td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @else
                <x-ui.card>
                    <x-ui.empty-state icon="chart" title="لا توجد مصاريف في هذه الفترة" />
                </x-ui.card>
            @endif

            @canDo('reports.export')
                <x-ui.card title="تصدير">
                    <div class="flex flex-col gap-2">
                        <x-ui.button
                            :href="route('admin.reports.export', ['report' => 'expenses', 'format' => 'pdf', 'from' => $from->toDateString(), 'to' => $to->toDateString()])"
                            variant="secondary" icon="download">تقرير المصاريف PDF</x-ui.button>
                        <x-ui.button
                            :href="route('admin.reports.export', ['report' => 'revenue', 'format' => 'xlsx', 'from' => $from->toDateString(), 'to' => $to->toDateString()])"
                            variant="secondary" icon="download">تقرير الإيرادات Excel</x-ui.button>
                    </div>
                </x-ui.card>
            @endcanDo
        </div>
    </div>
@endsection
