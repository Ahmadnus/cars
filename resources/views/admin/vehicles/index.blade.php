@extends('layouts.app')

@section('title', 'المركبات')
@section('subtitle', $vehicles->total() . ' مركبة')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المركبات' => null]" />
@endsection

@section('content')
    <x-layout.page-header title="أسطول المركبات" description="مركبات التدريب وحالتها وأوراقها الرسمية.">
        <x-slot:actions>
            <x-ui.button :href="route('admin.maintenance.index')" variant="secondary" icon="wrench">الصيانة</x-ui.button>
            @canDo('vehicles.manage')
                <x-ui.button :href="route('admin.vehicles.create')" icon="plus">مركبة جديدة</x-ui.button>
            @endcanDo
        </x-slot:actions>
    </x-layout.page-header>

    {{-- Expiring papers deserve a banner, not a buried column --}}
    @if ($expiring->isNotEmpty())
        <x-ui.alert type="warning" class="mb-4" title="أوراق على وشك الانتهاء">
            <ul class="mt-1 space-y-1 text-xs">
                @foreach ($expiring as $vehicle)
                    @foreach ($vehicle->expiringPapers(30) as $type => $date)
                        <li>
                            <a href="{{ route('admin.vehicles.show', $vehicle) }}" class="font-medium underline">
                                {{ $vehicle->name }} ({{ $vehicle->plate_number }})
                            </a>
                            — {{ $type === 'insurance' ? 'التأمين' : 'الترخيص' }} ينتهي في {{ $date->format('Y-m-d') }}
                        </li>
                    @endforeach
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <x-ui.filters :action="route('admin.vehicles.index')" placeholder="ابحث بالاسم أو رقم اللوحة أو الطراز…">
        <x-slot:fields>
            <x-form.select name="status" label="الحالة" :options="$statuses" :selected="request('status')" placeholder="كل الحالات" />
            <x-form.select name="transmission" label="ناقل الحركة" :options="['manual' => 'عادي', 'automatic' => 'أوتوماتيك']" :selected="request('transmission')" placeholder="الكل" />
        </x-slot:fields>
    </x-ui.filters>

    @if ($vehicles->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="car" title="لا توجد مركبات" description="أضف أول مركبة لبدء إسنادها للحصص." />
        </x-ui.card>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($vehicles as $vehicle)
                <x-ui.card>
                    <div class="mb-3 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('admin.vehicles.show', $vehicle) }}" class="block truncate font-semibold text-ink-900 hover:text-brand-600">
                                {{ $vehicle->name }}
                            </a>
                            <p class="font-mono text-xs text-ink-400" dir="ltr">{{ $vehicle->plate_number }}</p>
                        </div>
                        <x-ui.status type="vehicle" :value="$vehicle->status" />
                    </div>

                    <dl class="space-y-1.5 text-sm">
                        @foreach ([
                            'الطراز' => trim(($vehicle->model ?? '') . ' ' . ($vehicle->year ?? '')) ?: '—',
                            'ناقل الحركة' => $vehicle->transmission === 'automatic' ? 'أوتوماتيك' : 'عادي',
                            'المدرب المسؤول' => $vehicle->assignedTrainer?->full_name ?? '—',
                            'حصص منجزة' => $vehicle->completed_lessons_count,
                            'قراءة العداد' => $vehicle->odometer_km ? number_format($vehicle->odometer_km) . ' كم' : '—',
                        ] as $label => $value)
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-500">{{ $label }}</dt>
                                <dd class="truncate font-medium text-ink-800">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @php $papers = $vehicle->expiringPapers(30); @endphp
                    @if (! empty($papers))
                        <div class="mt-3 rounded-lg bg-amber-50 p-2 text-xs text-amber-800">
                            @foreach ($papers as $type => $date)
                                <p>{{ $type === 'insurance' ? 'التأمين' : 'الترخيص' }} ينتهي {{ $date->format('Y-m-d') }}</p>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-4 flex gap-2 border-t border-ink-100 pt-3">
                        <x-ui.button :href="route('admin.vehicles.show', $vehicle)" variant="secondary" size="sm" class="flex-1">التفاصيل</x-ui.button>
                        @canDo('vehicles.manage')
                            <x-ui.button :href="route('admin.vehicles.edit', $vehicle)" variant="ghost" size="sm" icon="edit" />
                        @endcanDo
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif

    <div class="mt-4">{{ $vehicles->links() }}</div>
@endsection
