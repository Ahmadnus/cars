@extends('layouts.app')

@section('title', 'قواعد أجور المدربين')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="[
        'أجور المدربين' => route('admin.trainer-compensation.index'),
        'قواعد الأجور' => null,
    ]" />
@endsection

@section('content')
    <x-layout.page-header
        title="قواعد أجور المدربين"
        description="تغيير القاعدة يفتح قاعدة جديدة بتاريخ سريان ويغلق السابقة، لتبقى الكشوف التاريخية قابلة لإعادة الاحتساب."
    />

    <div class="space-y-4">
        @foreach ($trainers as $trainer)
            @php $current = $trainer->compensationRules->firstWhere('effective_to', null); @endphp

            <x-ui.card x-data="{ editing: false }">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <a href="{{ route('admin.trainers.show', $trainer) }}" class="font-semibold text-ink-900 hover:text-brand-600">
                            {{ $trainer->full_name }}
                        </a>
                        <p class="font-mono text-xs text-ink-400" dir="ltr">{{ $trainer->trainer_number }}</p>

                        @if ($current)
                            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                                <x-ui.badge tone="brand">{{ $current->modelLabel() }}</x-ui.badge>

                                @if ($current->usesSalary())
                                    <span class="text-ink-600">راتب: <span class="tabular-nums">{{ money($current->base_salary) }}</span></span>
                                @endif
                                @if ($current->usesPerLesson())
                                    <span class="text-ink-600">لكل حصة: <span class="tabular-nums">{{ money($current->per_lesson_rate) }}</span></span>
                                @endif
                                @if ($current->usesPercentage())
                                    <span class="text-ink-600">نسبة: <span class="tabular-nums">{{ percent($current->revenue_percentage, 0) }}</span></span>
                                @endif

                                <span class="text-xs text-ink-400">سارية من {{ $current->effective_from->format('Y-m-d') }}</span>
                            </div>
                        @else
                            <p class="mt-2 text-sm text-amber-700">لا توجد قاعدة أجر — لن يتم احتساب أجر لهذا المدرب.</p>
                        @endif
                    </div>

                    @canDo('trainer_compensation.manage')
                        <x-ui.button type="button" variant="secondary" size="sm" @click="editing = !editing">
                            {{ $current ? 'تغيير القاعدة' : 'تحديد القاعدة' }}
                        </x-ui.button>
                    @endcanDo
                </div>

                @canDo('trainer_compensation.manage')
                    <div x-show="editing" x-collapse x-cloak class="mt-4 border-t border-ink-200 pt-4">
                        <form method="POST" action="{{ route('admin.trainer-compensation.rules.store', $trainer) }}"
                              class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @csrf

                            <x-form.select name="model" label="نموذج الأجر" :options="$models" placeholder="اختر النموذج" required />
                            <x-form.input name="base_salary" type="number" step="0.01" min="0" label="الراتب الأساسي" value="0" />
                            <x-form.input name="per_lesson_rate" type="number" step="0.01" min="0" label="المبلغ لكل حصة" value="0" />
                            <x-form.input name="revenue_percentage" type="number" step="0.01" min="0" max="100" label="نسبة من الإيراد (%)" value="0" />
                            <x-form.input name="effective_from" type="date" label="تاريخ السريان"
                                          :value="now()->addMonth()->startOfMonth()->toDateString()" required />
                            <x-form.input name="notes" label="ملاحظات" />

                            <div class="sm:col-span-2 lg:col-span-3">
                                <x-ui.button type="submit" size="sm">حفظ القاعدة الجديدة</x-ui.button>
                            </div>
                        </form>
                    </div>
                @endcanDo

                @if ($trainer->compensationRules->count() > 1)
                    <details class="mt-3 border-t border-ink-100 pt-3">
                        <summary class="cursor-pointer text-xs text-ink-500 hover:text-ink-700">
                            القواعد السابقة ({{ $trainer->compensationRules->count() - 1 }})
                        </summary>
                        <ul class="mt-2 space-y-1.5 text-xs text-ink-500">
                            @foreach ($trainer->compensationRules->where('effective_to', '!=', null) as $rule)
                                <li class="flex flex-wrap items-center gap-2">
                                    <span>{{ $rule->modelLabel() }}</span>
                                    <span class="text-ink-400">
                                        {{ $rule->effective_from->format('Y-m-d') }} → {{ $rule->effective_to?->format('Y-m-d') }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </x-ui.card>
        @endforeach
    </div>
@endsection
