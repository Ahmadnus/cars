@extends('layouts.app')

@section('title', 'إسناد باقة تدريبية')
@section('subtitle', $trainee->full_name)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="[
        'المتدربون' => route('admin.trainees.index'),
        $trainee->full_name => route('admin.trainees.show', $trainee),
        'إسناد باقة' => null,
    ]" />
@endsection

@section('content')
@php
    // Built here rather than inline in @js(): Blade's directive-argument parser
    // does not handle array literals nested inside an arrow function.
    $packageData = $packages->mapWithKeys(fn ($p) => [
        $p->id => [
            'price' => (float) $p->price,
            'lessons' => $p->lessons_count,
            'maxDiscount' => (float) $p->max_discount_percent,
        ],
    ]);

    $formState = [
        'packageId' => old('package_id', ''),
        'discount' => (float) old('discount_amount', 0),
        'packages' => $packageData,
    ];
@endphp

<form method="POST" action="{{ route('admin.trainees.packages.store', $trainee) }}"
      x-data="@js($formState)"
      class="grid gap-5 lg:grid-cols-3">
    @csrf

    <div class="lg:col-span-2">
        <x-ui.card title="اختيار الباقة">
            @if ($packages->isEmpty())
                <x-ui.empty-state icon="package" title="لا توجد باقات نشطة" description="أنشئ باقة أولاً من صفحة الباقات." />
            @else
                <div class="space-y-3">
                    @foreach ($packages as $package)
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-ink-200 p-4 transition has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/50">
                            <input type="radio" name="package_id" value="{{ $package->id }}"
                                   x-model="packageId"
                                   @checked(old('package_id') == $package->id)
                                   required
                                   class="mt-1 size-4 border-ink-300 text-brand-600 focus:ring-brand-500">

                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="font-semibold text-ink-900">{{ $package->name }}</span>
                                    <span class="text-lg font-bold tabular-nums text-brand-700">{{ money($package->price) }}</span>
                                </span>
                                <span class="mt-1 block text-sm text-ink-500">
                                    {{ $package->lessons_count }} حصة × {{ $package->lesson_duration_minutes }} دقيقة ·
                                    الحصة الإضافية {{ money($package->extra_lesson_price) }}
                                    @if ($package->max_discount_percent > 0)
                                        · أقصى خصم {{ percent($package->max_discount_percent, 0) }}
                                    @endif
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="mt-5 grid gap-4 border-t border-ink-200 pt-5 sm:grid-cols-2">
                    <x-form.input name="started_on" type="date" label="تاريخ البدء" :value="now()->toDateString()" required />

                    @canDo('packages.discount')
                        <x-form.input name="discount_amount" type="number" step="0.01" min="0" label="قيمة الخصم"
                                      value="0" x-model.number="discount" />
                        <x-form.input name="discount_reason" label="سبب الخصم" class="sm:col-span-2"
                                      x-bind:required="discount > 0"
                                      hint="مطلوب عند منح أي خصم، ويُسجَّل في سجل التدقيق." />
                    @endcanDo

                    <x-form.textarea name="notes" label="ملاحظات" rows="2" class="sm:col-span-2" />
                </div>
            @endif
        </x-ui.card>
    </div>

    <div class="space-y-5">
        <x-ui.card title="ملخص العقد">
            <dl class="space-y-2 text-sm" x-data="{
                selected() { return this.packages[this.packageId] ?? null; },
                gross() { return this.selected()?.price ?? 0; },
                total() { return Math.max(0, this.gross() - (this.discount || 0)); },
                fmt(v) { return (v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' {{ settings('center.currency_label', 'د.أ') }}'; },
            }">
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">عدد الحصص</dt>
                    <dd class="font-medium tabular-nums text-ink-900" x-text="selected()?.lessons ?? '—'">—</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">سعر الباقة</dt>
                    <dd class="tabular-nums text-ink-800" x-text="fmt(gross())">—</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">الخصم</dt>
                    <dd class="tabular-nums text-rose-600" x-text="fmt(discount)">—</dd>
                </div>
                <div class="flex justify-between gap-3 border-t border-ink-200 pt-2">
                    <dt class="font-semibold text-ink-700">الإجمالي</dt>
                    <dd class="text-lg font-bold tabular-nums text-ink-900" x-text="fmt(total())">—</dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.alert type="info">
            سيتم إضافة حصص الباقة إلى رصيد المتدرب فور الإسناد، وتسجيل الحركة في سجل الحصص.
        </x-ui.alert>

        <x-ui.card>
            <div class="flex flex-col gap-2">
                {{-- Bound attribute rather than @disabled(): a Blade directive
                     inside a component tag corrupts the component parser. --}}
                <x-ui.button type="submit" size="lg" :disabled="$packages->isEmpty()">إسناد الباقة</x-ui.button>
                <x-ui.button :href="route('admin.trainees.show', $trainee)" variant="secondary">إلغاء</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</form>
@endsection
