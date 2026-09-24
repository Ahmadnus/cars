@extends('layouts.app')

@section('title', 'حجز حصة تدريبية')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الحصص التدريبية' => route('admin.sessions.index'), 'حجز حصة' => null]" />
@endsection

@section('content')
<form method="POST" action="{{ route('admin.sessions.store') }}"
      x-data="slotFinder()" class="grid gap-5 lg:grid-cols-3">
    @csrf

    <div class="space-y-5 lg:col-span-2">
        <x-ui.card title="تفاصيل الحصة">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.select
                    name="trainee_id"
                    label="المتدرب"
                    :options="$trainees"
                    :selected="$prefill['trainee_id']"
                    placeholder="اختر المتدرب"
                    required
                    class="sm:col-span-2"
                />

                <x-form.select
                    name="trainer_id"
                    label="المدرب"
                    :options="$trainers"
                    placeholder="اختر المدرب"
                    required
                    x-model="trainerId"
                    @change="loadSlots()"
                />

                <x-form.select
                    name="vehicle_id"
                    label="المركبة"
                    :options="$vehicles"
                    placeholder="بدون مركبة"
                    x-model="vehicleId"
                    @change="loadSlots()"
                />

                <x-form.input
                    name="scheduled_date"
                    type="date"
                    label="تاريخ الحصة"
                    :value="$prefill['scheduled_date']"
                    required
                    x-model="date"
                    @change="loadSlots()"
                />

                <x-form.input
                    name="duration_minutes"
                    type="number"
                    label="مدة الحصة (دقيقة)"
                    :value="$defaultDuration"
                    min="15"
                    max="300"
                    x-model="duration"
                    @change="loadSlots()"
                />

                <x-form.input
                    name="start_time"
                    type="time"
                    label="وقت البداية"
                    required
                    x-model="startTime"
                    class="sm:col-span-2"
                />
            </div>
        </x-ui.card>

        {{-- Availability is a convenience. The server re-checks every conflict
             on submit, so a stale suggestion can never create a double booking. --}}
        <x-ui.card title="الأوقات المتاحة" subtitle="حسب دوام الفرع والمدرب والحجوزات القائمة.">
            <div x-show="loading" class="py-6 text-center text-sm text-ink-400">جارٍ التحميل…</div>

            <div x-show="!loading && slots.length === 0" x-cloak class="py-6 text-center text-sm text-ink-400">
                اختر المدرب والتاريخ لعرض الأوقات المتاحة.
            </div>

            <div x-show="!loading && slots.length > 0" x-cloak class="flex flex-wrap gap-2">
                <template x-for="slot in slots" :key="slot.start">
                    <button type="button" @click="startTime = slot.start"
                            class="rounded-lg border px-3 py-1.5 font-mono text-sm transition"
                            :class="startTime === slot.start
                                ? 'border-brand-600 bg-brand-600 text-white'
                                : 'border-ink-200 text-ink-700 hover:border-brand-300 hover:bg-brand-50'"
                            dir="ltr"
                            x-text="slot.start"></button>
                </template>
            </div>
        </x-ui.card>
    </div>

    <div class="space-y-5">
        <x-ui.card>
            <div class="flex flex-col gap-2">
                <x-ui.button type="submit" size="lg">تأكيد الحجز</x-ui.button>
                <x-ui.button :href="route('admin.sessions.index')" variant="secondary">إلغاء</x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.alert type="info" title="قواعد الحجز">
            <ul class="list-disc space-y-1 ps-4 text-xs">
                <li>لا يمكن حجز المدرب أو المتدرب أو المركبة في وقت محجوز.</li>
                <li>يجب أن يكون الموعد ضمن ساعات عمل الفرع ودوام المدرب.</li>
                <li>يجب توفر رصيد حصص كافٍ لدى المتدرب.</li>
            </ul>
        </x-ui.alert>
    </div>
</form>

@push('scripts')
<script>
    function slotFinder() {
        return {
            trainerId: '{{ old('trainer_id') }}',
            vehicleId: '{{ old('vehicle_id') }}',
            date: '{{ old('scheduled_date', $prefill['scheduled_date']) }}',
            duration: '{{ old('duration_minutes', $defaultDuration) }}',
            startTime: '{{ old('start_time') }}',
            slots: [],
            loading: false,

            init() {
                if (this.trainerId && this.date) this.loadSlots();
            },

            async loadSlots() {
                if (!this.trainerId || !this.date) {
                    this.slots = [];
                    return;
                }

                this.loading = true;

                const params = new URLSearchParams({
                    trainer_id: this.trainerId,
                    date: this.date,
                    duration_minutes: this.duration || 45,
                });

                if (this.vehicleId) params.append('vehicle_id', this.vehicleId);

                try {
                    const response = await fetch('{{ route('admin.sessions.slots') }}?' + params, {
                        headers: { 'Accept': 'application/json' },
                    });
                    const data = await response.json();
                    this.slots = data.slots ?? [];
                } catch {
                    this.slots = [];
                } finally {
                    this.loading = false;
                }
            },
        };
    }
</script>
@endpush
@endsection
