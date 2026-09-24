@php
    $employee ??= null;
    $branches ??= [];
    $positions ??= [];
    $statuses ??= [];
    $days = \App\Http\Controllers\Admin\BranchController::days();
    $selectedDays = old('working_days', $employee?->working_days ?? [0, 1, 2, 3, 4]);
@endphp

<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <x-ui.card title="البيانات الشخصية">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input name="full_name" label="الاسم الكامل" :value="$employee?->full_name" required class="sm:col-span-2" />
                <x-form.input name="phone" label="رقم الهاتف" :value="$employee?->phone" required dir="ltr" />
                <x-form.input name="national_id" label="الرقم الوطني" :value="$employee?->national_id" dir="ltr" />
                <x-form.select name="position" label="الوظيفة" :options="$positions" :selected="$employee?->position" required />
                <x-form.input name="employment_date" type="date" label="تاريخ التعيين"
                              :value="$employee?->employment_date?->format('Y-m-d') ?? now()->toDateString()" required />
            </div>
        </x-ui.card>

        <x-ui.card title="الراتب">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input name="base_salary" type="number" step="0.01" min="0" label="الراتب الأساسي"
                              :value="$employee?->base_salary ?? 0" required />
                <x-form.input name="allowances" type="number" step="0.01" min="0" label="البدلات الشهرية"
                              :value="$employee?->allowances ?? 0" required />

                @if ($employee)
                    <x-form.input name="reason" label="سبب تعديل الراتب" class="sm:col-span-2"
                                  hint="مطلوب عند تغيير الراتب أو البدلات، ويُسجَّل في سجل التدقيق." />
                @endif
            </div>
        </x-ui.card>

        <x-ui.card title="الدوام">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input name="work_start_time" type="time" label="بداية الدوام"
                              :value="short_time($employee?->work_start_time) !== '—' ? short_time($employee?->work_start_time) : '08:00'" />
                <x-form.input name="work_end_time" type="time" label="نهاية الدوام"
                              :value="short_time($employee?->work_end_time) !== '—' ? short_time($employee?->work_end_time) : '16:00'" />
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
            </div>
        </x-ui.card>

        <x-ui.card title="ملاحظات">
            <x-form.textarea name="notes" :value="$employee?->notes" rows="3" />
        </x-ui.card>
    </div>

    <div class="space-y-5">
        <x-ui.card title="الحالة والفرع">
            <div class="space-y-4">
                <x-form.select name="status" label="الحالة" :options="$statuses" :selected="$employee?->status ?? 'active'" required />

                @if (count($branches) > 1)
                    <x-form.select name="branch_id" label="الفرع" :options="$branches"
                                   :selected="$employee?->branch_id ?? branch_context()->currentId()" />
                @elseif (count($branches) === 1)
                    <input type="hidden" name="branch_id" value="{{ array_key_first($branches) }}">
                @endif
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="flex flex-col gap-2">
                <x-ui.button type="submit" size="lg">{{ $employee ? 'حفظ التعديلات' : 'إضافة الموظف' }}</x-ui.button>
                <x-ui.button :href="route('admin.employees.index')" variant="secondary">إلغاء</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
