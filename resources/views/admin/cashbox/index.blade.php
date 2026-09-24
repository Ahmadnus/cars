@extends('layouts.app')

@section('title', 'الصندوق')
@section('subtitle', 'الرصيد الحالي: ' . money($balance))

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الصندوق' => null]" />
@endsection

@section('content')
    <x-layout.page-header title="صندوق المركز" description="حركات النقد، الإقفال اليومي، والتسويات.">
        <x-slot:actions>
            @canDo('cashbox.manage')
                <x-ui.button type="button" variant="secondary" @click="$dispatch('open-modal', 'adjust')">تسوية</x-ui.button>
                <x-ui.button type="button" @click="$dispatch('open-modal', 'close-day')" icon="safe">إقفال يومي</x-ui.button>
            @endcanDo
        </x-slot:actions>
    </x-layout.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ui.stat-card label="الرصيد الحالي" :value="money($balance)" icon="safe" :tone="$balance >= 0 ? 'success' : 'danger'" />
        <x-ui.stat-card label="وارد اليوم" :value="money($today['in'])" icon="banknote" tone="success" />
        <x-ui.stat-card label="صادر اليوم" :value="money($today['out'])" icon="trending-down" tone="danger" />
        <x-ui.stat-card label="صافي اليوم" :value="money($today['net'])" icon="chart" :tone="$today['net'] >= 0 ? 'brand' : 'warning'" />
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-ui.filters :action="route('admin.cashbox.index')" :collapsible="false">
                <x-slot:fields>
                    <x-form.input name="from" type="date" label="من" :value="$from->format('Y-m-d')" />
                    <x-form.input name="to" type="date" label="إلى" :value="$to->format('Y-m-d')" />
                    <x-form.select name="direction" label="النوع" :options="['in' => 'وارد', 'out' => 'صادر']" :selected="request('direction')" placeholder="الكل" />
                    <x-form.select name="category" label="التصنيف" :options="$categories" :selected="request('category')" placeholder="الكل" />
                </x-slot:fields>
            </x-ui.filters>

            <x-ui.card padded="false" :title="'حركات الصندوق — وارد ' . money($periodIn) . ' · صادر ' . money($periodOut)">
                @if ($transactions->isEmpty())
                    <x-ui.empty-state icon="safe" title="لا توجد حركات في هذه الفترة" />
                @else
                    <x-ui.table :headers="['التاريخ', 'البيان', 'التصنيف', ['label' => 'وارد', 'align' => 'end'], ['label' => 'صادر', 'align' => 'end'], ['label' => 'الرصيد', 'align' => 'end']]">
                        @foreach ($transactions as $transaction)
                            <tr @class(['bg-ink-50/60' => $transaction->reverses_id])>
                                <td class="whitespace-nowrap px-3 py-2.5 text-ink-600">{{ $transaction->transaction_date->format('Y-m-d') }}</td>
                                <td class="px-3 py-2.5">
                                    <span class="text-ink-800">{{ $transaction->description ?? '—' }}</span>
                                    @if ($transaction->reverses_id)
                                        <x-ui.badge tone="warning" class="ms-1">عكسية</x-ui.badge>
                                    @endif
                                    <span class="block text-xs text-ink-400">{{ $transaction->creator?->name }}</span>
                                </td>
                                <td class="px-3 py-2.5 text-xs text-ink-500">{{ $categories[$transaction->category] ?? $transaction->category }}</td>
                                <td class="px-3 py-2.5 text-end tabular-nums text-emerald-700">
                                    {{ $transaction->direction === 'in' ? money($transaction->amount, false) : '—' }}
                                </td>
                                <td class="px-3 py-2.5 text-end tabular-nums text-rose-700">
                                    {{ $transaction->direction === 'out' ? money($transaction->amount, false) : '—' }}
                                </td>
                                <td class="px-3 py-2.5 text-end font-medium tabular-nums text-ink-900">
                                    {{ money($transaction->balance_after, false) }}
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>

            <div>{{ $transactions->links() }}</div>
        </div>

        <div class="space-y-5">
            <x-ui.card title="الصناديق">
                <ul class="space-y-3">
                    @foreach ($boxes as $box)
                        <li class="flex items-center justify-between gap-3 border-b border-ink-100 pb-3 last:border-0 last:pb-0">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-ink-900">{{ $box->name }}</span>
                                <span class="block text-xs text-ink-400">{{ $box->branch?->name }}</span>
                            </span>
                            <span class="shrink-0 text-end">
                                <span class="block font-semibold tabular-nums text-ink-900">{{ money($box->current_balance) }}</span>
                                @unless ($box->isConsistent())
                                    <x-ui.badge tone="danger">غير مطابق</x-ui.badge>
                                @endunless
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>

            <x-ui.card padded="false" title="آخر الإقفالات">
                @if ($closings->isEmpty())
                    <x-ui.empty-state icon="check" title="لا توجد إقفالات" />
                @else
                    <x-ui.table :headers="['التاريخ', 'المتوقع', 'المعدود', 'الفرق']">
                        @foreach ($closings as $closing)
                            <tr>
                                <td class="whitespace-nowrap px-3 py-2.5">{{ $closing->closing_date->format('Y-m-d') }}</td>
                                <td class="px-3 py-2.5 tabular-nums text-ink-600">{{ money($closing->expected_balance, false) }}</td>
                                <td class="px-3 py-2.5 tabular-nums text-ink-600">{{ money($closing->counted_balance, false) }}</td>
                                <td class="px-3 py-2.5 tabular-nums {{ $closing->isBalanced() ? 'text-emerald-700' : 'text-rose-700' }}">
                                    {{ money($closing->difference, false) }}
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
        </div>
    </div>

    @canDo('cashbox.manage')
        <x-ui.modal name="close-day" title="الإقفال اليومي للصندوق">
            <form method="POST" action="{{ route('admin.cashbox.close') }}" id="close-day-form" class="space-y-4">
                @csrf
                <x-form.select name="cashbox_id" label="الصندوق"
                               :options="$boxes->pluck('name', 'id')->all()" required />
                <x-form.input name="closing_date" type="date" label="تاريخ الإقفال" :value="now()->toDateString()" required />
                <x-form.input name="counted_balance" type="number" step="0.01" min="0" label="الرصيد المعدود فعلياً" required
                              hint="سيتم مقارنته بالرصيد المتوقع من دفتر الحركات وتسجيل أي فرق." />
                <x-form.textarea name="notes" label="ملاحظات" rows="2" />
            </form>

            <x-slot:footer>
                <x-ui.button type="button" variant="secondary" size="sm" @click="open = false">إلغاء</x-ui.button>
                <x-ui.button type="submit" size="sm" form="close-day-form">تأكيد الإقفال</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        <x-ui.modal name="adjust" title="تسوية الصندوق">
            <form method="POST" action="{{ route('admin.cashbox.adjust') }}" id="adjust-form" class="space-y-4">
                @csrf
                <x-form.select name="cashbox_id" label="الصندوق" :options="$boxes->pluck('name', 'id')->all()" required />
                <x-form.select name="direction" label="نوع الحركة" :options="['in' => 'إيداع', 'out' => 'سحب']" required />
                <x-form.input name="amount" type="number" step="0.01" min="0.01" label="المبلغ" required />
                <x-form.input name="transaction_date" type="date" label="التاريخ" :value="now()->toDateString()" required />
                <x-form.input name="description" label="البيان" required />

                <x-ui.alert type="info">
                    تُسجَّل التسوية كحركة مستقلة في دفتر الصندوق ولا تُعدّل أي حركة سابقة.
                </x-ui.alert>
            </form>

            <x-slot:footer>
                <x-ui.button type="button" variant="secondary" size="sm" @click="open = false">إلغاء</x-ui.button>
                <x-ui.button type="submit" size="sm" form="adjust-form">تسجيل التسوية</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcanDo
@endsection
