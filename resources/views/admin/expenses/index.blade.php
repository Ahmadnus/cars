@extends('layouts.app')

@section('title', 'المصاريف')
@section('subtitle', 'إجمالي المصاريف: ' . money($total))

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المصاريف' => null]" />
@endsection

@section('content')
    <x-layout.page-header title="المصاريف" description="سجل المصاريف التشغيلية للمركز.">
        <x-slot:actions>
            @canDo('expenses.create')
                <x-ui.button :href="route('admin.expenses.create')" icon="plus">تسجيل مصروف</x-ui.button>
            @endcanDo
            @canDo('reports.export')
                <x-ui.button :href="route('admin.reports.show', 'expenses')" variant="secondary" icon="file-text">تقرير المصاريف</x-ui.button>
            @endcanDo
        </x-slot:actions>
    </x-layout.page-header>

    <x-ui.filters :action="route('admin.expenses.index')" placeholder="ابحث بالبيان أو المرجع أو المستفيد…">
        <x-slot:fields>
            <x-form.select name="expense_category_id" label="التصنيف" :options="$categories" :selected="request('expense_category_id')" placeholder="كل التصنيفات" />
            <x-form.select name="payment_method_id" label="طريقة الدفع" :options="$methods" :selected="request('payment_method_id')" placeholder="الكل" />
            <x-form.input name="from" type="date" label="من تاريخ" :value="request('from')" />
            <x-form.input name="to" type="date" label="إلى تاريخ" :value="request('to')" />
        </x-slot:fields>
    </x-ui.filters>

    <x-ui.card padded="false">
        @if ($expenses->isEmpty())
            <x-ui.empty-state icon="trending-down" title="لا توجد مصاريف مطابقة" />
        @else
            <x-ui.table :headers="['المرجع', 'التاريخ', 'البيان', 'التصنيف', 'المستفيد', ['label' => 'المبلغ', 'align' => 'end'], 'الحالة', ['label' => '', 'align' => 'end']]">
                @foreach ($expenses as $expense)
                    <tr @class(['hover:bg-ink-50', 'opacity-60' => $expense->isCancelled()])>
                        <td class="px-3 py-3 font-mono text-xs text-ink-700" dir="ltr">{{ $expense->reference }}</td>
                        <td class="whitespace-nowrap px-3 py-3 text-ink-600">{{ $expense->spent_on->format('Y-m-d') }}</td>
                        <td class="px-3 py-3">
                            <span class="text-ink-900">{{ $expense->title }}</span>
                            @if ($expense->isSystemGenerated())
                                <x-ui.badge tone="info" class="ms-1">تلقائي</x-ui.badge>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-ink-600">{{ $expense->category?->name_ar }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ $expense->beneficiary ?: '—' }}</td>
                        <td class="px-3 py-3 text-end font-semibold tabular-nums {{ $expense->isCancelled() ? 'text-ink-400 line-through' : 'text-ink-900' }}">
                            {{ money($expense->amount) }}
                        </td>
                        <td class="px-3 py-3"><x-ui.status type="expense" :value="$expense->status" /></td>
                        <td class="px-3 py-3 text-end">
                            <x-ui.button :href="route('admin.expenses.show', $expense)" variant="ghost" size="sm">عرض</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $expenses->links() }}</div>
@endsection
