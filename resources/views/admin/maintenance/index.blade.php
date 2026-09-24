@extends('layouts.app')

@section('title', 'صيانة المركبات')
@section('subtitle', 'إجمالي التكلفة: ' . money($total))

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المركبات' => route('admin.vehicles.index'), 'الصيانة' => null]" />
@endsection

@section('content')
    <x-layout.page-header title="صيانة المركبات" description="سجل الصيانة والإصلاحات وقطع الغيار.">
        <x-slot:actions>
            @canDo('vehicles.manage')
                <x-ui.button type="button" @click="$dispatch('open-modal', 'new-maintenance')" icon="plus">تسجيل صيانة</x-ui.button>
            @endcanDo
        </x-slot:actions>
    </x-layout.page-header>

    <x-ui.filters :action="route('admin.maintenance.index')" :collapsible="false">
        <x-slot:fields>
            <x-form.select name="vehicle_id" label="المركبة" :options="$vehicles" :selected="request('vehicle_id')" placeholder="كل المركبات" />
            <x-form.select name="type" label="النوع" :options="$types" :selected="request('type')" placeholder="كل الأنواع" />
            <x-form.input name="from" type="date" label="من" :value="request('from')" />
            <x-form.input name="to" type="date" label="إلى" :value="request('to')" />
        </x-slot:fields>
    </x-ui.filters>

    <x-ui.card padded="false">
        @if ($maintenances->isEmpty())
            <x-ui.empty-state icon="wrench" title="لا توجد سجلات صيانة" />
        @else
            <x-ui.table :headers="['التاريخ', 'المركبة', 'النوع', 'البيان', 'الجهة', ['label' => 'العداد', 'align' => 'end'], ['label' => 'التكلفة', 'align' => 'end'], 'مرحّل']">
                @foreach ($maintenances as $maintenance)
                    <tr class="hover:bg-ink-50">
                        <td class="whitespace-nowrap px-3 py-3">{{ $maintenance->service_date->format('Y-m-d') }}</td>
                        <td class="px-3 py-3">
                            <span class="block text-ink-900">{{ $maintenance->vehicle?->name }}</span>
                            <span class="block font-mono text-xs text-ink-400" dir="ltr">{{ $maintenance->vehicle?->plate_number }}</span>
                        </td>
                        <td class="px-3 py-3"><x-ui.badge>{{ $types[$maintenance->type] ?? $maintenance->type }}</x-ui.badge></td>
                        <td class="px-3 py-3 text-ink-800">{{ $maintenance->title }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ $maintenance->provider ?: '—' }}</td>
                        <td class="px-3 py-3 text-end tabular-nums text-ink-500">
                            {{ $maintenance->odometer_km ? number_format($maintenance->odometer_km) : '—' }}
                        </td>
                        <td class="px-3 py-3 text-end font-semibold tabular-nums">{{ money($maintenance->cost) }}</td>
                        <td class="px-3 py-3">
                            @if ($maintenance->expense)
                                <a href="{{ route('admin.expenses.show', $maintenance->expense) }}" class="font-mono text-xs text-brand-600 hover:underline" dir="ltr">
                                    {{ $maintenance->expense->reference }}
                                </a>
                            @else
                                <span class="text-xs text-ink-400">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $maintenances->links() }}</div>

    @canDo('vehicles.manage')
        <x-ui.modal name="new-maintenance" title="تسجيل صيانة" max-width="xl">
            <form method="POST" action="{{ route('admin.maintenance.store') }}" id="new-maintenance-form"
                  x-data="{ post: false }" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <x-form.select name="vehicle_id" label="المركبة" :options="$vehicles"
                               :selected="request('vehicle_id')" placeholder="اختر المركبة" required />
                <x-form.select name="type" label="نوع الصيانة" :options="$types" required />
                <x-form.input name="title" label="البيان" required class="sm:col-span-2" />
                <x-form.input name="service_date" type="date" label="تاريخ الصيانة" :value="now()->toDateString()" required />
                <x-form.input name="cost" type="number" step="0.01" min="0" label="التكلفة" value="0" required />
                <x-form.input name="provider" label="الجهة المنفذة" />
                <x-form.input name="odometer_km" type="number" min="0" label="قراءة العداد" />
                <x-form.input name="next_service_on" type="date" label="موعد الصيانة القادمة" />
                <x-form.textarea name="notes" label="ملاحظات" rows="2" class="sm:col-span-2" />

                <div class="sm:col-span-2">
                    <x-form.checkbox name="post_to_expenses" label="ترحيل التكلفة إلى المصاريف"
                                     hint="يسجّل التكلفة كمصروف ويخصمها من الصندوق إن كان الدفع نقدياً." x-model="post" />
                </div>

                <div class="sm:col-span-2" x-show="post" x-cloak>
                    <x-form.select name="payment_method_id" label="طريقة الدفع" :options="$methods" placeholder="اختر الطريقة" />
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button type="button" variant="secondary" size="sm" @click="open = false">إلغاء</x-ui.button>
                <x-ui.button type="submit" size="sm" form="new-maintenance-form">حفظ</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcanDo
@endsection
