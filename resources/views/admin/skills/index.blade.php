@extends('layouts.app')

@section('title', 'مهارات التدريب')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['مهارات التدريب' => null]" />
@endsection

@section('content')
    <x-layout.page-header
        title="منهاج التدريب"
        description="المهارات التي تُقيَّم في كل حصة. يمكن إضافة مهارات خاصة بالمركز."
    >
        <x-slot:actions>
            @canDo('skills.manage')
                <x-ui.button type="button" @click="$dispatch('open-modal', 'new-skill')" icon="plus">مهارة جديدة</x-ui.button>
            @endcanDo
        </x-slot:actions>
    </x-layout.page-header>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($skills as $skill)
            @php
                $rows = $distribution[$skill->id] ?? collect();
                $total = $rows->sum('total');
            @endphp

            <x-ui.card @class(['opacity-70' => $skill->status !== 'active'])>
                <div class="mb-3 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="truncate font-semibold text-ink-900">{{ $skill->name_ar }}</h3>
                        <p class="font-mono text-xs text-ink-400" dir="ltr">{{ $skill->code }}</p>
                    </div>
                    <x-ui.status type="generic" :value="$skill->status" />
                </div>

                @if ($skill->description)
                    <p class="mb-3 text-sm text-ink-500">{{ $skill->description }}</p>
                @endif

                {{-- Cohort distribution: how the whole trainee base sits on this skill --}}
                @if ($total > 0)
                    <div class="mb-3">
                        <div class="mb-1.5 flex h-2 overflow-hidden rounded-full bg-ink-100">
                            @foreach (['needs_training' => 'bg-rose-400', 'average' => 'bg-amber-400', 'good' => 'bg-sky-400', 'very_good' => 'bg-brand-400', 'excellent' => 'bg-emerald-500'] as $level => $colour)
                                @php $count = $rows->firstWhere('level', $level)?->total ?? 0; @endphp
                                @if ($count > 0)
                                    <span class="{{ $colour }}" style="width: {{ round($count / $total * 100) }}%"
                                          title="{{ $levels[$level] }}: {{ $count }}"></span>
                                @endif
                            @endforeach
                        </div>
                        <p class="text-xs text-ink-400">{{ $total }} تقييم مسجّل</p>
                    </div>
                @else
                    <p class="mb-3 text-xs text-ink-400">لا توجد تقييمات بعد.</p>
                @endif

                @canDo('skills.manage')
                    <form method="POST" action="{{ route('admin.skills.update', $skill) }}"
                          class="flex flex-wrap items-end gap-2 border-t border-ink-100 pt-3">
                        @csrf
                        @method('PATCH')
                        <x-form.input name="name_ar" :value="$skill->name_ar" label="" class="min-w-0 flex-1" />
                        <x-form.input name="sort_order" type="number" :value="$skill->sort_order" label="" class="w-20" />
                        <select name="status" class="rounded-lg border-ink-300 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            <option value="active" @selected($skill->status === 'active')>نشطة</option>
                            <option value="inactive" @selected($skill->status === 'inactive')>معطّلة</option>
                        </select>
                        <x-ui.button type="submit" variant="secondary" size="md">حفظ</x-ui.button>
                    </form>
                @endcanDo
            </x-ui.card>
        @endforeach
    </div>

    @canDo('skills.manage')
        <x-ui.modal name="new-skill" title="إضافة مهارة تدريب">
            <form method="POST" action="{{ route('admin.skills.store') }}" id="new-skill-form" class="space-y-4">
                @csrf
                <x-form.input name="name_ar" label="اسم المهارة" required />
                <x-form.input name="code" label="المعرّف" required dir="ltr"
                              hint="حروف إنجليزية صغيرة وأرقام وشرطة سفلية، مثل: night_driving" />
                <x-form.textarea name="description" label="الوصف" rows="2" />
            </form>

            <x-slot:footer>
                <x-ui.button type="button" variant="secondary" size="sm" @click="open = false">إلغاء</x-ui.button>
                <x-ui.button type="submit" size="sm" form="new-skill-form">إضافة</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcanDo
@endsection
