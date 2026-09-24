{{-- Shared by the create and edit screens; included, not a component, so it
     reads the same $errors and old() state as the form around it. --}}
@php
    $trainee ??= null;
    $trainers ??= [];
    $branches ??= [];
    $statuses ??= [];
@endphp

<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <x-ui.card title="البيانات الشخصية">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input name="full_name" label="الاسم الكامل" :value="$trainee?->full_name" required class="sm:col-span-2" />
                <x-form.input name="phone" label="رقم الهاتف" :value="$trainee?->phone" required dir="ltr" placeholder="07XXXXXXXX" />
                <x-form.input name="secondary_phone" label="هاتف إضافي" :value="$trainee?->secondary_phone" dir="ltr" />
                <x-form.input name="national_id" label="الرقم الوطني" :value="$trainee?->national_id" dir="ltr" />
                <x-form.input name="birth_date" type="date" label="تاريخ الميلاد" :value="$trainee?->birth_date?->format('Y-m-d')" />
                <x-form.select
                    name="gender"
                    label="الجنس"
                    :options="['male' => 'ذكر', 'female' => 'أنثى']"
                    :selected="$trainee?->gender"
                    placeholder="غير محدد"
                />
                <x-form.input name="address" label="العنوان" :value="$trainee?->address" />
            </div>
        </x-ui.card>

        <x-ui.card title="بيانات التدريب">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.select
                    name="license_type"
                    label="نوع الرخصة"
                    :options="['private' => 'خصوصي', 'motorcycle' => 'دراجة نارية', 'light_truck' => 'شحن خفيف', 'public' => 'عمومي']"
                    :selected="$trainee?->license_type ?? 'private'"
                    required
                />
                <x-form.input
                    name="registration_date"
                    type="date"
                    label="تاريخ التسجيل"
                    :value="$trainee?->registration_date?->format('Y-m-d') ?? now()->toDateString()"
                    required
                />
                <x-form.select name="trainer_id" label="المدرب المسؤول" :options="$trainers" :selected="$trainee?->trainer_id" placeholder="بدون مدرب" />
                <x-form.select name="status" label="الحالة" :options="$statuses" :selected="$trainee?->status ?? 'new'" required />

                @if ($trainee)
                    <x-form.input name="exam_date" type="date" label="موعد الامتحان" :value="$trainee->exam_date?->format('Y-m-d')" />
                @endif
            </div>
        </x-ui.card>

        <x-ui.card title="ملاحظات">
            <x-form.textarea name="notes" :value="$trainee?->notes" rows="4" hint="ملاحظات داخلية لا تظهر للمتدرب." />
        </x-ui.card>
    </div>

    <div class="space-y-5">
        <x-ui.card title="الصورة الشخصية">
            @if ($trainee?->photo_path)
                <img src="{{ Storage::disk('public')->url($trainee->photo_path) }}" alt=""
                     class="mb-3 size-28 rounded-xl object-cover">
            @endif

            <x-form.field label="رفع صورة" name="photo" hint="JPG أو PNG بحد أقصى 4 ميجابايت.">
                <input type="file" name="photo" accept="image/*"
                       class="block w-full text-sm text-ink-600 file:me-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100">
            </x-form.field>
        </x-ui.card>

        @if (count($branches) > 1)
            <x-ui.card title="الفرع">
                <x-form.select name="branch_id" label="الفرع" :options="$branches" :selected="$trainee?->branch_id ?? branch_context()->currentId()" />
            </x-ui.card>
        @elseif (count($branches) === 1)
            <input type="hidden" name="branch_id" value="{{ array_key_first($branches) }}">
        @endif

        <x-ui.card>
            <div class="flex flex-col gap-2">
                <x-ui.button type="submit" size="lg">{{ $trainee ? 'حفظ التعديلات' : 'تسجيل المتدرب' }}</x-ui.button>
                <x-ui.button :href="route('admin.trainees.index')" variant="secondary">إلغاء</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
