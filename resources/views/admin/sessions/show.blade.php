@extends('layouts.app')

@section('title', 'تفاصيل الحصة')
@section('subtitle', $session->scheduled_date->format('Y-m-d') . ' — ' . $session->timeRange())

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="[
        'الحصص التدريبية' => route('admin.sessions.index'),
        'حصة ' . $session->scheduled_date->format('Y-m-d') => null,
    ]" />
@endsection

@section('content')
@php
    $canComplete = auth()->user()->can('complete', $session);
    $canCancel = auth()->user()->can('cancel', $session);
    $canReopen = auth()->user()->can('reopen', $session);
    $ratedSkills = $session->skills->keyBy('training_skill_id');
@endphp

<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">

        <x-ui.card title="معلومات الحصة">
            <x-slot:actions>
                <x-ui.status type="session" :value="$session->status" />
            </x-slot:actions>

            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-ink-500">المتدرب</dt>
                    <dd class="mt-0.5">
                        <a href="{{ route('admin.trainees.show', $session->trainee) }}" class="font-medium text-brand-700 hover:underline">
                            {{ $session->trainee->full_name }}
                        </a>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-500">المدرب</dt>
                    <dd class="mt-0.5 font-medium text-ink-900">{{ $session->trainer->full_name }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-500">المركبة</dt>
                    <dd class="mt-0.5 font-medium text-ink-900">
                        {{ $session->vehicle ? $session->vehicle->name . ' — ' . $session->vehicle->plate_number : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-500">المدة</dt>
                    <dd class="mt-0.5 font-medium text-ink-900">{{ $session->duration_minutes }} دقيقة</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-500">التاريخ والوقت</dt>
                    <dd class="mt-0.5 font-medium text-ink-900">
                        {{ arabic_date($session->scheduled_date) }} — <span class="font-mono" dir="ltr">{{ $session->timeRange() }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-500">الباقة</dt>
                    <dd class="mt-0.5 font-medium text-ink-900">{{ $session->traineePackage?->package_name ?? '—' }}</dd>
                </div>
            </dl>

            @if ($session->status === 'cancelled' && $session->cancellation_reason)
                <x-ui.alert type="error" class="mt-4" title="سبب الإلغاء">
                    {{ $session->cancellation_reason }}
                    <span class="mt-1 block text-xs opacity-75">
                        بواسطة {{ $session->canceller?->name ?? '—' }} · {{ $session->cancelled_at?->format('Y-m-d H:i') }}
                    </span>
                </x-ui.alert>
            @endif
        </x-ui.card>

        @if ($session->isCompleted())
            <x-ui.card title="تقييم الحصة">
                <x-slot:actions>
                    @if ($session->overall_rating)
                        <x-ui.status type="rating" :value="$session->overall_rating" />
                    @endif
                </x-slot:actions>

                <dl class="space-y-4">
                    @foreach ([
                        'نقاط القوة' => $session->strengths,
                        'نقاط تحتاج تحسين' => $session->weaknesses,
                        'ملاحظات المدرب' => $session->trainer_notes,
                        'متطلبات الحصة القادمة' => $session->next_requirements,
                    ] as $label => $value)
                        @if ($value)
                            <div>
                                <dt class="text-xs font-medium text-ink-500">{{ $label }}</dt>
                                <dd class="mt-1 whitespace-pre-line text-sm text-ink-700">{{ $value }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>

                @if ($session->skills->isNotEmpty())
                    <div class="mt-5 border-t border-ink-200 pt-4">
                        <p class="mb-3 text-xs font-medium text-ink-500">المهارات المتدرَّب عليها</p>
                        <ul class="space-y-2">
                            @foreach ($session->skills as $entry)
                                <li class="flex items-center justify-between gap-3">
                                    <span class="text-sm text-ink-700">{{ $entry->skill->name_ar }}</span>
                                    <x-ui.status type="rating" :value="$entry->rating" />
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <p class="mt-4 border-t border-ink-100 pt-3 text-xs text-ink-400">
                    أُنهيت بواسطة {{ $session->completer?->name ?? '—' }} · {{ $session->completed_at?->format('Y-m-d H:i') }}
                </p>
            </x-ui.card>
        @elseif ($canComplete && ! $session->isSettled())
            {{-- Completion form: this is the action that consumes a lesson --}}
            <x-ui.card title="إنهاء الحصة وتسجيل التقييم">
                @if ($session->startsAt()->isFuture())
                    <x-ui.alert type="info">لا يمكن إنهاء الحصة قبل موعدها.</x-ui.alert>
                @else
                    <form method="POST" action="{{ route('admin.sessions.complete', $session) }}" class="space-y-5">
                        @csrf

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-form.select
                                name="overall_rating"
                                label="التقييم العام"
                                :options="collect($ratings)->except('not_started')->all()"
                                placeholder="اختر تقييماً"
                                required
                            />
                            <x-form.input name="duration_minutes" type="number" label="المدة الفعلية (دقيقة)"
                                          :value="$session->duration_minutes" min="10" max="300" />
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-form.textarea name="strengths" label="نقاط القوة" rows="3" />
                            <x-form.textarea name="weaknesses" label="نقاط تحتاج تحسين" rows="3" />
                            <x-form.textarea name="trainer_notes" label="ملاحظات المدرب" rows="3" />
                            <x-form.textarea name="next_requirements" label="متطلبات الحصة القادمة" rows="3" />
                        </div>

                        <div>
                            <p class="mb-2 text-sm font-medium text-ink-700">تقييم المهارات</p>
                            <div class="space-y-2 rounded-lg border border-ink-200 p-3">
                                @foreach ($skills as $i => $skill)
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <label for="skill_{{ $skill->id }}" class="text-sm text-ink-700">{{ $skill->name_ar }}</label>
                                        <input type="hidden" name="skills[{{ $i }}][skill_id]" value="{{ $skill->id }}">
                                        <select id="skill_{{ $skill->id }}" name="skills[{{ $i }}][rating]"
                                                class="w-40 rounded-lg border-ink-300 py-1.5 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                            @foreach ($ratings as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endforeach
                            </div>
                            <p class="mt-1.5 text-xs text-ink-400">
                                المهارات المتروكة على «لم يبدأ» لن تغيّر مستوى المتدرب الحالي.
                            </p>
                        </div>

                        <x-ui.alert type="warning">
                            سيتم خصم حصة واحدة من رصيد المتدرب عند الإنهاء، وتسجيل العملية في سجل التدقيق.
                        </x-ui.alert>

                        <x-ui.button type="submit" size="lg">إنهاء الحصة وخصم الرصيد</x-ui.button>
                    </form>
                @endif
            </x-ui.card>
        @endif
    </div>

    {{-- Actions --}}
    <div class="space-y-5">
        <x-ui.card title="الإجراءات">
            <div class="flex flex-col gap-2">
                @if (! $session->isSettled())
                    @canDo('appointments.update')
                        <x-ui.button :href="route('admin.sessions.edit', $session)" variant="secondary" icon="edit">
                            تعديل الموعد
                        </x-ui.button>
                    @endcanDo

                    @if ($canComplete)
                        <x-ui.confirm
                            :action="route('admin.sessions.no-show', $session)"
                            title="تسجيل عدم حضور"
                            message="سيتم تسجيل عدم حضور المتدرب، وقد يتم خصم حصة من رصيده حسب سياسة المركز."
                            confirm-label="تسجيل عدم الحضور"
                            variant="danger"
                            reason-label="ملاحظة (اختياري)"
                            reason-name="note"
                        >
                            <x-slot:trigger>
                                <x-ui.button type="button" variant="secondary" class="w-full">تسجيل عدم حضور</x-ui.button>
                            </x-slot:trigger>
                        </x-ui.confirm>
                    @endif

                    @if ($canCancel)
                        <x-ui.confirm
                            :action="route('admin.sessions.postpone', $session)"
                            title="تأجيل الحصة"
                            message="سيتم تأجيل الحصة دون تحديد موعد جديد."
                            confirm-label="تأجيل"
                            variant="secondary"
                            reason-label="سبب التأجيل"
                        >
                            <x-slot:trigger>
                                <x-ui.button type="button" variant="secondary" class="w-full">تأجيل الحصة</x-ui.button>
                            </x-slot:trigger>
                        </x-ui.confirm>

                        <x-ui.confirm
                            :action="route('admin.sessions.cancel', $session)"
                            title="إلغاء الحصة"
                            message="قد يتم خصم حصة من الرصيد إذا كان الإلغاء ضمن مهلة الإشعار المحددة في الإعدادات."
                            confirm-label="إلغاء الحصة"
                            reason-label="سبب الإلغاء"
                        >
                            <x-slot:trigger>
                                <x-ui.button type="button" variant="danger" class="w-full">إلغاء الحصة</x-ui.button>
                            </x-slot:trigger>
                        </x-ui.confirm>
                    @endif
                @endif

                @if ($canReopen && in_array($session->status, ['completed', 'no_show'], true))
                    <x-ui.confirm
                        :action="route('admin.sessions.reopen', $session)"
                        title="إعادة فتح الحصة"
                        message="سيتم إرجاع الحصة إلى رصيد المتدرب وإعادة حالتها إلى «مجدولة». تُسجَّل العملية في سجل التدقيق."
                        confirm-label="إعادة الفتح"
                        variant="secondary"
                        reason-label="سبب إعادة الفتح"
                    >
                        <x-slot:trigger>
                            <x-ui.button type="button" variant="secondary" class="w-full">إعادة فتح الحصة</x-ui.button>
                        </x-slot:trigger>
                    </x-ui.confirm>
                @endif

                @if ($session->isSettled() && ! $canReopen)
                    <p class="text-sm text-ink-400">لا توجد إجراءات متاحة على حصة منتهية.</p>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card title="سجل الحصة">
            <dl class="space-y-2 text-xs">
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">أنشأها</dt>
                    <dd class="text-ink-700">{{ $session->creator?->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">تاريخ الإنشاء</dt>
                    <dd class="text-ink-700">{{ $session->created_at->format('Y-m-d H:i') }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">آخر تحديث</dt>
                    <dd class="text-ink-700">{{ $session->updated_at->format('Y-m-d H:i') }}</dd>
                </div>
            </dl>
        </x-ui.card>
    </div>
</div>
@endsection
