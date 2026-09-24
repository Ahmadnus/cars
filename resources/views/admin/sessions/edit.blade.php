@extends('layouts.app')

@section('title', 'تعديل موعد الحصة')
@section('subtitle', $session->trainee->full_name)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="[
        'الحصص التدريبية' => route('admin.sessions.index'),
        'تفاصيل الحصة' => route('admin.sessions.show', $session),
        'تعديل' => null,
    ]" />
@endsection

@section('content')
<form method="POST" action="{{ route('admin.sessions.update', $session) }}" class="grid gap-5 lg:grid-cols-3">
    @csrf
    @method('PATCH')

    <div class="lg:col-span-2">
        <x-ui.card title="الموعد الجديد">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.select name="trainer_id" label="المدرب" :options="$trainers" :selected="$session->trainer_id" required />
                <x-form.select name="vehicle_id" label="المركبة" :options="$vehicles" :selected="$session->vehicle_id" placeholder="بدون مركبة" />
                <x-form.input name="scheduled_date" type="date" label="التاريخ" :value="$session->scheduled_date->format('Y-m-d')" required />
                <x-form.input name="start_time" type="time" label="وقت البداية" :value="short_time($session->start_time)" required />
                <x-form.input name="duration_minutes" type="number" label="المدة (دقيقة)" :value="$session->duration_minutes" min="15" max="300" />
                <x-form.input name="reason" label="سبب التعديل" hint="يُسجَّل في سجل التدقيق." />
            </div>
        </x-ui.card>
    </div>

    <div class="space-y-5">
        <x-ui.card title="الموعد الحالي">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">المتدرب</dt>
                    <dd class="font-medium text-ink-900">{{ $session->trainee->full_name }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">التاريخ</dt>
                    <dd class="text-ink-700">{{ $session->scheduled_date->format('Y-m-d') }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">الوقت</dt>
                    <dd class="font-mono text-ink-700" dir="ltr">{{ $session->timeRange() }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card>
            <div class="flex flex-col gap-2">
                <x-ui.button type="submit" size="lg">حفظ الموعد الجديد</x-ui.button>
                <x-ui.button :href="route('admin.sessions.show', $session)" variant="secondary">إلغاء</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</form>
@endsection
