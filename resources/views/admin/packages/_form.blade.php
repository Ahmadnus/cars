@php
    $package ??= null;
    $branches ??= [];
@endphp

<div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <x-ui.card title="تفاصيل الباقة">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input name="name" label="اسم الباقة" :value="$package?->name" required class="sm:col-span-2" />

                <x-form.select
                    name="license_type"
                    label="نوع الرخصة"
                    :options="['private' => 'خصوصي', 'motorcycle' => 'دراجة نارية', 'light_truck' => 'شحن خفيف', 'public' => 'عمومي']"
                    :selected="$package?->license_type ?? 'private'"
                    required
                />

                <x-form.select name="status" label="الحالة"
                               :options="['active' => 'نشطة', 'inactive' => 'غير نشطة']"
                               :selected="$package?->status ?? 'active'" required />

                <x-form.input name="lessons_count" type="number" min="1" max="200" label="عدد الحصص"
                              :value="$package?->lessons_count ?? 10" required />

                <x-form.input name="lesson_duration_minutes" type="number" min="15" max="300" label="مدة الحصة (دقيقة)"
                              :value="$package?->lesson_duration_minutes ?? 45" required />

                <x-form.input name="price" type="number" step="0.01" min="0" label="سعر الباقة"
                              :value="$package?->price" required />

                <x-form.input name="extra_lesson_price" type="number" step="0.01" min="0" label="سعر الحصة الإضافية"
                              :value="$package?->extra_lesson_price ?? 0" required />

                <x-form.input name="max_discount_percent" type="number" step="0.01" min="0" max="100"
                              label="أقصى نسبة خصم مسموحة (%)"
                              :value="$package?->max_discount_percent ?? 0" required
                              hint="يمنع النظام أي خصم يتجاوز هذه النسبة." />

                <x-form.input name="validity_days" type="number" min="1" max="3650" label="مدة الصلاحية (يوم)"
                              :value="$package?->validity_days" hint="اتركه فارغاً لباقة بلا تاريخ انتهاء." />

                <x-form.textarea name="description" label="الوصف" rows="3" :value="$package?->description" class="sm:col-span-2" />

                @if ($package)
                    <x-form.input name="price_change_reason" label="سبب تغيير السعر" class="sm:col-span-2"
                                  hint="مطلوب فقط عند تعديل الأسعار، ويُسجَّل في سجل التدقيق." />
                @endif
            </div>
        </x-ui.card>
    </div>

    <div class="space-y-5">
        @if (count($branches) > 1)
            <x-ui.card title="نطاق الباقة">
                <x-form.select name="branch_id" label="الفرع" :options="$branches"
                               :selected="$package?->branch_id" placeholder="متاحة لكل الفروع" />
            </x-ui.card>
        @endif

        <x-ui.alert type="info">
            تغيير السعر لا يؤثر على الباقات المُسندة سابقاً — تحتفظ كل باقة بشروطها وقت الإسناد.
        </x-ui.alert>

        <x-ui.card>
            <div class="flex flex-col gap-2">
                <x-ui.button type="submit" size="lg">{{ $package ? 'حفظ التعديلات' : 'إنشاء الباقة' }}</x-ui.button>
                <x-ui.button :href="route('admin.packages.index')" variant="secondary">إلغاء</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
