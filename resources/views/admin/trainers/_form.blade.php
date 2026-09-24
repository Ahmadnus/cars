@php
    $trainer ??= null;
    $branches ??= [];
    $statuses ??= [];
    $days = \App\Http\Controllers\Admin\BranchController::days();
    $selectedDays = old('working_days', $trainer?->working_days ?? [0, 1, 2, 3, 4]);
    $selectedLicenses = old('license_types', $trainer?->license_types ?? ['private']);
@endphp

<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <x-ui.card title="البيانات الشخصية">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input name="full_name" label="الاسم الكامل" :value="$trainer?->full_name" required class="sm:col-span-2" />
                <x-form.input name="phone" label="رقم الهاتف" :value="$trainer?->phone" required dir="ltr" />
                <x-form.input name="national_id" label="الرقم الوطني" :value="$trainer?->national_id" dir="ltr" />
                <x-form.input name="birth_date" type="date" label="تاريخ الميلاد" :value="$trainer?->birth_date?->format('Y-m-d')" />
                <x-form.input name="employment_date" type="date" label="تاريخ التعيين"
                              :value="$trainer?->employment_date?->format('Y-m-d') ?? now()->toDateString()" required />
                <x-form.input name="address" label="العنوان" :value="$trainer?->address" class="sm:col-span-2" />
            </div>
        </x-ui.card>

        <x-ui.card title="الدوام والاختصاص">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input name="work_start_time" type="time" label="بداية الدوام"
                              :value="short_time($trainer?->work_start_time) !== '—' ? short_time($trainer?->work_start_time) : '08:00'" />
                <x-form.input name="work_end_time" type="time" label="نهاية الدوام"
                              :value="short_time($trainer?->work_end_time) !== '—' ? short_time($trainer?->work_end_time) : '17:00'" />
            </div>

            <div class="mt-4">
                <p class="mb-2 text-sm font-medium text-ink-700">أيام الدوام</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($days as $value => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-ink-200 px-3 py-1.5 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="checkbox" name="working_days[]" value="{{ $value }}"
                                   @checked(in_array($value, (array) $selectedDays))
                                   class="size-3.5 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                <p class="mt-1.5 text-xs text-ink-400">تُستخدم هذه الأيام لمنع حجز حصص خارج دوام المدرب.</p>
            </div>

            <div class="mt-4">
                <p class="mb-2 text-sm font-medium text-ink-700">أنواع الرخص</p>
                <div class="flex flex-wrap gap-2">
                    @foreach (['private' => 'خصوصي', 'motorcycle' => 'دراجة نارية', 'light_truck' => 'شحن خفيف', 'public' => 'عمومي'] as $value => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-ink-200 px-3 py-1.5 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="checkbox" name="license_types[]" value="{{ $value }}"
                                   @checked(in_array($value, (array) $selectedLicenses))
                                   class="size-3.5 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="ملاحظات">
            <x-form.textarea name="notes" :value="$trainer?->notes" rows="3" />
        </x-ui.card>
    </div>

    <div class="space-y-5">
        <x-ui.card title="الحالة والفرع">
            <div class="space-y-4">
                <x-form.select name="status" label="الحالة" :options="$statuses" :selected="$trainer?->status ?? 'active'" required />

                @if (count($branches) > 1)
                    <x-form.select name="branch_id" label="الفرع" :options="$branches"
                                   :selected="$trainer?->branch_id ?? branch_context()->currentId()" />
                @elseif (count($branches) === 1)
                    <input type="hidden" name="branch_id" value="{{ array_key_first($branches) }}">
                @endif
            </div>
        </x-ui.card>

        @unless ($trainer)
            <x-ui.alert type="info">
                بعد إضافة المدرب، حدّد قاعدة الأجر الخاصة به من صفحة «أجور المدربين».
            </x-ui.alert>
        @endunless

        <x-ui.card>
            <div class="flex flex-col gap-2">
                <x-ui.button type="submit" size="lg">{{ $trainer ? 'حفظ التعديلات' : 'إضافة المدرب' }}</x-ui.button>
                <x-ui.button :href="route('admin.trainers.index')" variant="secondary">إلغاء</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
