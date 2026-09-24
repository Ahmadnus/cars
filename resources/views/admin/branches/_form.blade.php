@php
    $branch ??= null;
    $organizations ??= [];
    $days ??= [];

    // Index the stored hours by day so each row can be pre-filled.
    $hours = collect($branch?->working_hours ?? [])->keyBy('day');
@endphp

<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <x-ui.card title="بيانات الفرع">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.select name="organization_id" label="المؤسسة" :options="$organizations"
                               :selected="$branch?->organization_id" required />
                <x-form.select name="status" label="الحالة"
                               :options="['active' => 'نشط', 'inactive' => 'غير نشط']"
                               :selected="$branch?->status ?? 'active'" required />
                <x-form.input name="name" label="اسم الفرع" :value="$branch?->name" required />
                <x-form.input name="code" label="رمز الفرع" :value="$branch?->code" required dir="ltr"
                              hint="حروف إنجليزية كبيرة وأرقام، مثل AMM." />
                <x-form.input name="phone" label="الهاتف" :value="$branch?->phone" dir="ltr" />
                <x-form.input name="email" type="email" label="البريد الإلكتروني" :value="$branch?->email" dir="ltr" />
                <x-form.input name="address" label="العنوان" :value="$branch?->address" class="sm:col-span-2" />
            </div>
        </x-ui.card>

        {{-- Working hours drive appointment validation, so they are part of the
             branch record rather than a setting. --}}
        <x-ui.card title="ساعات العمل" subtitle="تُستخدم لمنع حجز أي حصة خارج دوام الفرع.">
            <div class="space-y-2">
                @foreach ($days as $value => $label)
                    @php $entry = $hours[$value] ?? null; @endphp
                    <div class="flex flex-wrap items-center gap-3 rounded-lg border border-ink-200 p-3">
                        <span class="w-20 shrink-0 text-sm font-medium text-ink-700">{{ $label }}</span>

                        <label class="flex items-center gap-2 text-sm text-ink-600">
                            <input type="hidden" name="working_hours[{{ $value }}][closed]" value="0">
                            <input type="checkbox" name="working_hours[{{ $value }}][closed]" value="1"
                                   @checked($entry['closed'] ?? false)
                                   class="size-3.5 rounded border-ink-300 text-rose-600 focus:ring-rose-500">
                            مغلق
                        </label>

                        <div class="flex items-center gap-2">
                            <input type="time" name="working_hours[{{ $value }}][open]"
                                   value="{{ substr($entry['open'] ?? '08:00', 0, 5) }}"
                                   class="rounded-lg border-ink-300 py-1.5 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            <span class="text-ink-400">—</span>
                            <input type="time" name="working_hours[{{ $value }}][close]"
                                   value="{{ substr($entry['close'] ?? '18:00', 0, 5) }}"
                                   class="rounded-lg border-ink-300 py-1.5 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </div>

    <div class="space-y-5">
        @unless ($branch)
            <x-ui.alert type="info">
                سيتم إنشاء صندوق نقدي للفرع تلقائياً عند حفظه.
            </x-ui.alert>
        @endunless

        <x-ui.card>
            <div class="flex flex-col gap-2">
                <x-ui.button type="submit" size="lg">{{ $branch ? 'حفظ التعديلات' : 'إنشاء الفرع' }}</x-ui.button>
                <x-ui.button :href="route('admin.branches.index')" variant="secondary">إلغاء</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
