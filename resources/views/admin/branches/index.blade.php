@extends('layouts.app')

@section('title', 'الفروع')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الفروع' => null]" />
@endsection

@section('content')
    <x-layout.page-header title="الفروع" description="فروع المركز وساعات عملها.">
        <x-slot:actions>
            <x-ui.button :href="route('admin.branches.create')" icon="plus">فرع جديد</x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($branches as $branch)
            <x-ui.card>
                <div class="mb-3 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="truncate font-semibold text-ink-900">{{ $branch->name }}</h3>
                        <p class="font-mono text-xs text-ink-400" dir="ltr">{{ $branch->code }}</p>
                    </div>
                    <x-ui.status type="generic" :value="$branch->status" />
                </div>

                <dl class="space-y-1.5 text-sm">
                    @foreach ([
                        'المؤسسة' => $branch->organization?->name,
                        'الهاتف' => $branch->phone ?: '—',
                        'العنوان' => $branch->address ?: '—',
                    ] as $label => $value)
                        <div class="flex justify-between gap-3">
                            <dt class="shrink-0 text-ink-500">{{ $label }}</dt>
                            <dd class="min-w-0 truncate text-end font-medium text-ink-800">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                <div class="mt-3 grid grid-cols-4 gap-2 border-t border-ink-100 pt-3 text-center">
                    @foreach ([
                        'متدربون' => $branch->trainees_count,
                        'مدربون' => $branch->trainers_count,
                        'موظفون' => $branch->employees_count,
                        'مركبات' => $branch->vehicles_count,
                    ] as $label => $count)
                        <div>
                            <p class="text-sm font-semibold tabular-nums text-ink-900">{{ $count }}</p>
                            <p class="text-[11px] text-ink-400">{{ $label }}</p>
                        </div>
                    @endforeach
                </div>

                <x-ui.button :href="route('admin.branches.edit', $branch)" variant="secondary" size="sm" class="mt-4 w-full" icon="edit">
                    تعديل الفرع
                </x-ui.button>
            </x-ui.card>
        @endforeach
    </div>
@endsection
