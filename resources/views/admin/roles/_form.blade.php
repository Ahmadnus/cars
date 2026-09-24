@php
    $role ??= null;
    $catalog ??= [];
    $assigned ??= old('permissions', []);
    $assigned = old('permissions', $assigned);
@endphp

<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <x-ui.card title="الصلاحيات" subtitle="حدّد ما يستطيع حاملو هذا الدور فعله.">
            <div class="space-y-5">
                @foreach ($catalog as $groupKey => $group)
                    <div x-data="{
                        toggleAll(checked) {
                            this.$refs.group.querySelectorAll('input[type=checkbox]').forEach(el => el.checked = checked);
                        }
                    }">
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <p class="text-sm font-semibold text-ink-700">{{ $group['label'] }}</p>
                            <span class="flex gap-2 text-xs">
                                <button type="button" @click="toggleAll(true)" class="text-brand-600 hover:underline">تحديد الكل</button>
                                <button type="button" @click="toggleAll(false)" class="text-ink-400 hover:underline">إلغاء الكل</button>
                            </span>
                        </div>

                        <div x-ref="group" class="grid gap-2 sm:grid-cols-2">
                            @foreach ($group['permissions'] as $name => [$label, $sensitive])
                                <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-ink-200 p-2.5 transition has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/50">
                                    <input type="checkbox" name="permissions[]" value="{{ $name }}"
                                           @checked(in_array($name, (array) $assigned))
                                           class="mt-0.5 size-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
                                    <span class="min-w-0">
                                        <span class="flex flex-wrap items-center gap-1.5">
                                            <span class="text-sm text-ink-800">{{ $label }}</span>
                                            @if ($sensitive)
                                                <x-ui.badge tone="danger">حساسة</x-ui.badge>
                                            @endif
                                        </span>
                                        <span class="block font-mono text-[11px] text-ink-400" dir="ltr">{{ $name }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </div>

    <div class="space-y-5">
        <x-ui.card title="بيانات الدور">
            <div class="space-y-4">
                <x-form.input name="label_ar" label="اسم الدور" :value="$role?->label_ar" required />

                @if (! $role?->is_system)
                    <x-form.input name="name" label="المعرّف" :value="$role?->name" required dir="ltr"
                                  hint="حروف إنجليزية صغيرة وأرقام وشرطة سفلية." />
                @else
                    <div class="rounded-lg bg-ink-50 p-3">
                        <p class="text-xs text-ink-500">المعرّف</p>
                        <p class="mt-0.5 font-mono text-sm text-ink-700" dir="ltr">{{ $role->name }}</p>
                        <p class="mt-1 text-xs text-ink-400">معرّف أدوار النظام ثابت ولا يمكن تغييره.</p>
                    </div>
                @endif

                <x-form.textarea name="description" label="الوصف" rows="3" :value="$role?->description" />
            </div>
        </x-ui.card>

        <x-ui.alert type="warning">
            الصلاحيات المعلّمة كـ«حساسة» تكشف بيانات مالية أو شخصية. امنحها فقط لمن يحتاجها فعلاً.
        </x-ui.alert>

        <x-ui.card>
            <div class="flex flex-col gap-2">
                <x-ui.button type="submit" size="lg">{{ $role ? 'حفظ التعديلات' : 'إنشاء الدور' }}</x-ui.button>
                <x-ui.button :href="route('admin.roles.index')" variant="secondary">إلغاء</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
