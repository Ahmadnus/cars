@extends('layouts.app')

@section('title', $report->title)
@section('subtitle', ($filters['from'] ?? '') . ' — ' . ($filters['to'] ?? ''))

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['التقارير' => route('admin.reports.index'), $report->title => null]" />
@endsection

@section('content')
@php
    $exportParams = array_filter(collect($filters)->except('branch_ids')->all());
@endphp

<x-layout.page-header :title="$report->title" :description="$report->description">
    @canDo('reports.export')
        <x-slot:actions>
            @foreach (['pdf' => 'PDF', 'xlsx' => 'Excel', 'csv' => 'CSV'] as $format => $label)
                <x-ui.button
                    :href="route('admin.reports.export', array_merge(['report' => $report->key, 'format' => $format], $exportParams))"
                    variant="secondary" size="sm" icon="download">{{ $label }}</x-ui.button>
            @endforeach
        </x-slot:actions>
    @endcanDo
</x-layout.page-header>

{{-- Only the filters this report declares are offered --}}
<form method="GET" action="{{ route('admin.reports.show', $report->key) }}"
      class="mb-4 rounded-xl border border-ink-200 bg-white p-3">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @if ($report->accepts('from'))
            <x-form.input name="from" type="date" label="من تاريخ" :value="$filters['from'] ?? null" />
        @endif

        @if ($report->accepts('to'))
            <x-form.input name="to" type="date" label="إلى تاريخ" :value="$filters['to'] ?? null" />
        @endif

        @if ($report->accepts('period'))
            <x-form.input name="period" label="الشهر" :value="$filters['period'] ?? now()->format('Y-m')" dir="ltr" placeholder="YYYY-MM" />
        @endif

        @if ($report->accepts('branch_id') && count($options['branches']) > 1)
            <x-form.select name="branch_id" label="الفرع" :options="$options['branches']" :selected="$filters['branch_id'] ?? null" placeholder="كل الفروع المتاحة" />
        @endif

        @if ($report->accepts('trainer_id'))
            <x-form.select name="trainer_id" label="المدرب" :options="$options['trainers']" :selected="$filters['trainer_id'] ?? null" placeholder="كل المدربين" />
        @endif

        @if ($report->accepts('vehicle_id'))
            <x-form.select name="vehicle_id" label="المركبة" :options="$options['vehicles']" :selected="$filters['vehicle_id'] ?? null" placeholder="كل المركبات" />
        @endif

        @if ($report->accepts('expense_category_id'))
            <x-form.select name="expense_category_id" label="التصنيف" :options="$options['categories']" :selected="$filters['expense_category_id'] ?? null" placeholder="كل التصنيفات" />
        @endif

        @if ($report->accepts('payment_method_id'))
            <x-form.select name="payment_method_id" label="طريقة الدفع" :options="$options['methods']" :selected="$filters['payment_method_id'] ?? null" placeholder="الكل" />
        @endif

        @if ($report->accepts('status'))
            <x-form.input name="status" label="الحالة" :value="$filters['status'] ?? null" />
        @endif

        <div class="flex items-end gap-2">
            <x-ui.button type="submit" size="md">تطبيق</x-ui.button>
            <x-ui.button :href="route('admin.reports.show', $report->key)" variant="ghost" size="md">مسح</x-ui.button>
        </div>
    </div>
</form>

<x-ui.card padded="false">
    @if ($rows->isEmpty())
        <x-ui.empty-state icon="file-text" title="لا توجد بيانات" description="لا توجد سجلات مطابقة للفلاتر المحددة." />
    @else
        <x-ui.table :headers="collect($report->columns)->map(fn ($c, $k) => [
            'label' => $c['label'],
            'align' => in_array($c['type'] ?? 'text', ['money', 'number'], true) ? 'end' : 'start',
        ])->values()->all()">
            @foreach ($rows as $row)
                <tr class="hover:bg-ink-50">
                    @foreach ($report->columns as $key => $column)
                        @php
                            $value = $row->{$key} ?? null;
                            $type = $column['type'] ?? 'text';
                        @endphp
                        <td @class([
                                'px-3 py-2.5',
                                'text-end tabular-nums' => in_array($type, ['money', 'number'], true),
                                'whitespace-nowrap' => $type === 'date',
                            ])>
                            @switch($type)
                                @case('money') {{ money($value, false) }} @break
                                @case('date') {{ $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d') : '—' }} @break
                                @case('session_status') <x-ui.status type="session" :value="$value" /> @break
                                @case('trainee_status') <x-ui.status type="trainee" :value="$value" /> @break
                                @default {{ $value ?? '—' }}
                            @endswitch
                        </td>
                    @endforeach
                </tr>
            @endforeach

            @if (! empty($totals))
                <x-slot:footer>
                    <tr>
                        @foreach ($report->columns as $key => $column)
                            <td @class([
                                    'px-3 py-3',
                                    'text-end tabular-nums' => isset($totals[$key]),
                                ])>
                                @if ($loop->first)
                                    الإجمالي
                                @elseif (isset($totals[$key]))
                                    {{ ($column['type'] ?? '') === 'money' ? money($totals[$key], false) : number_format($totals[$key]) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                </x-slot:footer>
            @endif
        </x-ui.table>
    @endif
</x-ui.card>

@if (method_exists($rows, 'links'))
    <div class="mt-4">{{ $rows->links() }}</div>
@endif
@endsection
