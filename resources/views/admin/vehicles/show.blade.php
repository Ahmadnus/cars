@extends('layouts.app')

@section('title', $vehicle->name)
@section('subtitle', $vehicle->plate_number)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المركبات' => route('admin.vehicles.index'), $vehicle->name => null]" />
@endsection

@section('content')
<x-ui.card class="mb-5">
    <div class="flex flex-wrap items-start gap-4">
        <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-ink-100 text-ink-500">
            <x-ui.icon name="car" class="size-7" />
        </span>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold text-ink-900">{{ $vehicle->name }}</h2>
                <x-ui.status type="vehicle" :value="$vehicle->status" />
            </div>
            <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm text-ink-500">
                <div><dt class="inline">اللوحة:</dt> <dd class="inline font-mono text-ink-700" dir="ltr">{{ $vehicle->plate_number }}</dd></div>
                <div><dt class="inline">الطراز:</dt> <dd class="inline text-ink-700">{{ $vehicle->model }} {{ $vehicle->year }}</dd></div>
                <div><dt class="inline">ناقل الحركة:</dt> <dd class="inline text-ink-700">{{ $vehicle->transmission === 'automatic' ? 'أوتوماتيك' : 'عادي' }}</dd></div>
                <div><dt class="inline">الفرع:</dt> <dd class="inline text-ink-700">{{ $vehicle->branch?->name }}</dd></div>
            </dl>
        </div>

        @canDo('vehicles.manage')
            <x-ui.button :href="route('admin.vehicles.edit', $vehicle)" variant="secondary" size="sm" icon="edit">تعديل</x-ui.button>
        @endcanDo
    </div>
</x-ui.card>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <x-ui.stat-card label="المدرب المسؤول" :value="$vehicle->assignedTrainer?->full_name ?? '—'" icon="badge" tone="neutral" />
    <x-ui.stat-card label="قراءة العداد" :value="$vehicle->odometer_km ? number_format($vehicle->odometer_km) . ' كم' : '—'" icon="chart" tone="info" />
    <x-ui.stat-card label="عدد الصيانات" :value="$maintenances->count()" icon="wrench" tone="warning" />
    <x-ui.stat-card label="إجمالي تكلفة الصيانة" :value="money($maintenanceCost)" icon="banknote" tone="danger" />
</div>

<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <x-ui.card padded="false" title="سجل الصيانة">
            @if ($maintenances->isEmpty())
                <x-ui.empty-state icon="wrench" title="لا يوجد سجل صيانة" />
            @else
                <x-ui.table :headers="['التاريخ', 'النوع', 'البيان', 'الجهة', ['label' => 'التكلفة', 'align' => 'end'], 'مصروف']">
                    @foreach ($maintenances as $maintenance)
                        <tr>
                            <td class="whitespace-nowrap px-3 py-2.5">{{ $maintenance->service_date->format('Y-m-d') }}</td>
                            <td class="px-3 py-2.5">
                                <x-ui.badge>{{ \App\Http\Controllers\Admin\VehicleMaintenanceController::types()[$maintenance->type] ?? $maintenance->type }}</x-ui.badge>
                            </td>
                            <td class="px-3 py-2.5 text-ink-800">{{ $maintenance->title }}</td>
                            <td class="px-3 py-2.5 text-ink-600">{{ $maintenance->provider ?: '—' }}</td>
                            <td class="px-3 py-2.5 text-end tabular-nums">{{ money($maintenance->cost) }}</td>
                            <td class="px-3 py-2.5">
                                @if ($maintenance->expense_id)
                                    <x-ui.badge tone="success">مُرحّل</x-ui.badge>
                                @else
                                    <span class="text-xs text-ink-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>

        <x-ui.card padded="false" title="آخر الحصص">
            @if ($recentSessions->isEmpty())
                <x-ui.empty-state icon="steering" title="لا توجد حصص على هذه المركبة" />
            @else
                <x-ui.table :headers="['التاريخ', 'الوقت', 'المتدرب', 'المدرب', 'الحالة']">
                    @foreach ($recentSessions as $session)
                        <tr class="hover:bg-ink-50">
                            <td class="whitespace-nowrap px-3 py-2.5">{{ $session->scheduled_date->format('Y-m-d') }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 font-mono text-ink-600" dir="ltr">{{ $session->timeRange() }}</td>
                            <td class="px-3 py-2.5 text-ink-800">{{ $session->trainee->full_name }}</td>
                            <td class="px-3 py-2.5 text-ink-600">{{ $session->trainer->full_name }}</td>
                            <td class="px-3 py-2.5"><x-ui.status type="session" :value="$session->status" /></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    </div>

    <div class="space-y-5">
        <x-ui.card title="الأوراق الرسمية">
            <dl class="space-y-3 text-sm">
                @foreach ([
                    ['التأمين', $vehicle->insurance_number, $vehicle->insurance_expires_on],
                    ['الترخيص', $vehicle->registration_number, $vehicle->registration_expires_on],
                ] as [$label, $number, $expiry])
                    <div class="border-b border-ink-100 pb-3 last:border-0 last:pb-0">
                        <dt class="text-xs text-ink-500">{{ $label }}</dt>
                        <dd class="mt-0.5 flex items-center justify-between gap-3">
                            <span class="font-mono text-ink-800" dir="ltr">{{ $number ?: '—' }}</span>
                            @if ($expiry)
                                <x-ui.badge :tone="$expiry->isPast() ? 'danger' : ($expiry->lte(now()->addDays(30)) ? 'warning' : 'success')">
                                    {{ $expiry->format('Y-m-d') }}
                                </x-ui.badge>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.card>

        @if ($vehicle->notes)
            <x-ui.card title="ملاحظات">
                <p class="whitespace-pre-line text-sm text-ink-600">{{ $vehicle->notes }}</p>
            </x-ui.card>
        @endif

        @canDo('vehicles.manage')
            <x-ui.button :href="route('admin.maintenance.index', ['vehicle_id' => $vehicle->id])" variant="secondary" class="w-full" icon="wrench">
                تسجيل صيانة
            </x-ui.button>
        @endcanDo
    </div>
</div>
@endsection
