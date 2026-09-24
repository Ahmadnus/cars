@php
    $vehicle ??= null;
    $branches ??= [];
    $trainers ??= [];
    $statuses ??= [];
@endphp

<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <x-ui.card title="بيانات المركبة">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input name="name" label="اسم المركبة" :value="$vehicle?->name" required />
                <x-form.input name="plate_number" label="رقم اللوحة" :value="$vehicle?->plate_number" required dir="ltr" />
                <x-form.input name="model" label="الطراز" :value="$vehicle?->model" />
                <x-form.input name="year" type="number" min="1970" :max="date('Y') + 1" label="سنة الصنع" :value="$vehicle?->year" />
                <x-form.select name="transmission" label="ناقل الحركة"
                               :options="['manual' => 'عادي', 'automatic' => 'أوتوماتيك']"
                               :selected="$vehicle?->transmission ?? 'manual'" required />
                <x-form.select name="license_type" label="نوع الرخصة"
                               :options="['private' => 'خصوصي', 'motorcycle' => 'دراجة نارية', 'light_truck' => 'شحن خفيف', 'public' => 'عمومي']"
                               :selected="$vehicle?->license_type ?? 'private'" placeholder="غير محدد" />
                <x-form.input name="odometer_km" type="number" min="0" label="قراءة العداد (كم)" :value="$vehicle?->odometer_km" />
                <x-form.select name="assigned_trainer_id" label="المدرب المسؤول" :options="$trainers"
                               :selected="$vehicle?->assigned_trainer_id" placeholder="بدون إسناد" />
            </div>
        </x-ui.card>

        <x-ui.card title="الأوراق الرسمية">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input name="insurance_number" label="رقم التأمين" :value="$vehicle?->insurance_number" dir="ltr" />
                <x-form.input name="insurance_expires_on" type="date" label="انتهاء التأمين"
                              :value="$vehicle?->insurance_expires_on?->format('Y-m-d')" />
                <x-form.input name="registration_number" label="رقم الترخيص" :value="$vehicle?->registration_number" dir="ltr" />
                <x-form.input name="registration_expires_on" type="date" label="انتهاء الترخيص"
                              :value="$vehicle?->registration_expires_on?->format('Y-m-d')" />
            </div>
            <p class="mt-2 text-xs text-ink-400">ينبّه النظام قبل 30 يوماً من انتهاء أي من الأوراق.</p>
        </x-ui.card>

        <x-ui.card title="ملاحظات">
            <x-form.textarea name="notes" :value="$vehicle?->notes" rows="3" />
        </x-ui.card>
    </div>

    <div class="space-y-5">
        <x-ui.card title="الحالة والفرع">
            <div class="space-y-4">
                <x-form.select name="status" label="الحالة" :options="$statuses" :selected="$vehicle?->status ?? 'available'" required
                               hint="المركبات في الصيانة أو غير النشطة لا يمكن حجزها." />

                @if (count($branches) > 1)
                    <x-form.select name="branch_id" label="الفرع" :options="$branches"
                                   :selected="$vehicle?->branch_id ?? branch_context()->currentId()" />
                @elseif (count($branches) === 1)
                    <input type="hidden" name="branch_id" value="{{ array_key_first($branches) }}">
                @endif
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="flex flex-col gap-2">
                <x-ui.button type="submit" size="lg">{{ $vehicle ? 'حفظ التعديلات' : 'إضافة المركبة' }}</x-ui.button>
                <x-ui.button :href="route('admin.vehicles.index')" variant="secondary">إلغاء</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
