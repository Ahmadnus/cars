@extends('layouts.app')

@section('title', 'الإعدادات')
@section('subtitle', $branchName ? 'إعدادات فرع: ' . $branchName : 'الإعدادات العامة')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الإعدادات' => null]" />
@endsection

@section('content')
<div x-data="{ tab: 'center' }">
    @if ($branchId)
        <x-ui.alert type="info" class="mb-5">
            أنت تعدّل إعدادات فرع <strong>{{ $branchName }}</strong>. اختر «جميع الفروع» من أعلى الصفحة
            لتعديل الإعدادات العامة للمؤسسة.
        </x-ui.alert>
    @endif

    <div class="mb-5 overflow-x-auto scrollbar-thin">
        <nav class="flex gap-1 border-b border-ink-200" role="tablist">
            @foreach ([
                'center' => 'بيانات المركز',
                'training' => 'التدريب',
                'cancellation' => 'الإلغاء والتأجيل',
                'payroll' => 'الرواتب',
                'notifications' => 'الإشعارات',
                'payments' => 'طرق الدفع',
                'expenses' => 'تصنيفات المصاريف',
            ] as $key => $label)
                <button type="button" role="tab" @click="tab = '{{ $key }}'"
                        :aria-selected="tab === '{{ $key }}'"
                        class="whitespace-nowrap border-b-2 px-4 py-2.5 text-sm transition"
                        :class="tab === '{{ $key }}' ? 'border-brand-600 font-semibold text-brand-700' : 'border-transparent text-ink-500 hover:text-ink-800'">
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- The five settings groups share one form and one save --}}
    <form method="POST" action="{{ route('admin.settings.update') }}">
        @csrf
        @method('PATCH')

        <div x-show="tab === 'center'" x-cloak>
            <x-ui.card title="بيانات المركز" subtitle="تظهر في الإيصالات والتقارير المطبوعة.">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-form.input name="center[name]" label="اسم المركز" :value="$center['name'] ?? ''" required class="sm:col-span-2" />
                    <x-form.input name="center[phone]" label="الهاتف" :value="$center['phone'] ?? ''" dir="ltr" />
                    <x-form.input name="center[email]" type="email" label="البريد الإلكتروني" :value="$center['email'] ?? ''" dir="ltr" />
                    <x-form.input name="center[address]" label="العنوان" :value="$center['address'] ?? ''" />
                    <x-form.input name="center[currency_label]" label="رمز العملة" :value="$center['currency_label'] ?? 'د.أ'" required />
                </div>
            </x-ui.card>
        </div>

        <div x-show="tab === 'training'" x-cloak>
            <x-ui.card title="إعدادات التدريب">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-form.input name="training[default_lesson_duration]" type="number" min="15" max="300"
                                  label="مدة الحصة الافتراضية (دقيقة)" :value="$training['default_lesson_duration'] ?? 45" required />
                    <x-form.input name="training[slot_step_minutes]" type="number" min="5" max="120"
                                  label="الفاصل بين المواعيد المقترحة (دقيقة)" :value="$training['slot_step_minutes'] ?? 15" required />
                    <x-form.input name="training[low_balance_threshold]" type="number" min="0" max="20"
                                  label="حد التنبيه لانخفاض الرصيد" :value="$training['low_balance_threshold'] ?? 2" required
                                  hint="يُنبَّه الموظفون عندما يصل رصيد المتدرب إلى هذا العدد." />

                    <div class="sm:col-span-2">
                        <x-form.checkbox name="training[allow_negative_balance]"
                                         label="السماح برصيد حصص سالب"
                                         :checked="$training['allow_negative_balance'] ?? false"
                                         hint="غير مستحسن — يعطّل الحماية من استهلاك حصص غير مدفوعة." />
                    </div>
                </div>
            </x-ui.card>
        </div>

        <div x-show="tab === 'cancellation'" x-cloak>
            <x-ui.card title="سياسة الإلغاء والتأجيل">
                <div class="grid gap-4">
                    <x-form.input name="cancellation[min_notice_hours]" type="number" min="0" max="168"
                                  label="مهلة الإشعار قبل الإلغاء (ساعة)"
                                  :value="$cancellation['min_notice_hours'] ?? 12" required
                                  hint="الإلغاء ضمن هذه المهلة يُعتبر إلغاءً متأخراً." />

                    <x-form.checkbox name="cancellation[burn_lesson_on_late_cancel]"
                                     label="خصم حصة عند الإلغاء المتأخر"
                                     :checked="$cancellation['burn_lesson_on_late_cancel'] ?? true" />

                    <x-form.checkbox name="cancellation[burn_lesson_on_no_show]"
                                     label="خصم حصة عند عدم الحضور"
                                     :checked="$cancellation['burn_lesson_on_no_show'] ?? true" />
                </div>
            </x-ui.card>
        </div>

        <div x-show="tab === 'payroll'" x-cloak>
            <x-ui.card title="إعدادات الرواتب">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-form.input name="payroll[pay_day]" type="number" min="1" max="28" label="يوم صرف الرواتب"
                                  :value="$payroll['pay_day'] ?? 1" required />

                    <div class="sm:col-span-2">
                        <x-form.checkbox name="payroll[require_reason_on_edit]"
                                         label="طلب سبب عند تعديل الرواتب"
                                         :checked="$payroll['require_reason_on_edit'] ?? true"
                                         hint="يُسجَّل السبب في سجل التدقيق." />
                    </div>
                </div>
            </x-ui.card>
        </div>

        <div x-show="tab === 'notifications'" x-cloak>
            <x-ui.card title="الإشعارات">
                <div class="grid gap-4">
                    <x-form.input name="notifications[lesson_reminder_hours]" type="number" min="1" max="168"
                                  label="التذكير بالحصة قبل (ساعة)"
                                  :value="$notifications['lesson_reminder_hours'] ?? 24" required />

                    <div class="space-y-3 border-t border-ink-200 pt-4">
                        <x-form.checkbox name="notifications[enable_in_app]" label="الإشعارات داخل النظام"
                                         :checked="$notifications['enable_in_app'] ?? true" />
                        <x-form.checkbox name="notifications[enable_email]" label="البريد الإلكتروني"
                                         :checked="$notifications['enable_email'] ?? false" />
                        <x-form.checkbox name="notifications[enable_sms]" label="رسائل SMS"
                                         :checked="$notifications['enable_sms'] ?? false"
                                         hint="يتطلب ربط مزوّد خدمة. تُسجَّل الرسائل في السجلات حتى ذلك الحين." />
                        <x-form.checkbox name="notifications[enable_whatsapp]" label="واتساب"
                                         :checked="$notifications['enable_whatsapp'] ?? false"
                                         hint="يتطلب ربط مزوّد خدمة." />
                        <x-form.checkbox name="notifications[enable_push]" label="الإشعارات الفورية للتطبيقات"
                                         :checked="$notifications['enable_push'] ?? false"
                                         hint="يتطلب ربط مزوّد خدمة." />
                    </div>
                </div>
            </x-ui.card>
        </div>

        <div x-show="['center','training','cancellation','payroll','notifications'].includes(tab)" x-cloak class="mt-5">
            <x-ui.button type="submit" size="lg">حفظ الإعدادات</x-ui.button>
        </div>
    </form>

    {{-- Lookup tables are edited independently of the settings form --}}
    <div x-show="tab === 'payments'" x-cloak>
        <x-ui.card padded="false" title="طرق الدفع">
            <x-ui.table :headers="['المعرّف', 'الاسم', 'يؤثر على الصندوق', 'يتطلب مرجعاً', 'الحالة', '']">
                @foreach ($paymentMethods as $method)
                    <tr>
                        <form method="POST" action="{{ route('admin.settings.payment-methods.update', $method) }}">
                            @csrf
                            @method('PATCH')
                            <td class="px-3 py-2.5 font-mono text-xs text-ink-500" dir="ltr">{{ $method->code }}</td>
                            <td class="px-3 py-2.5">
                                <input type="text" name="label_ar" value="{{ $method->label_ar }}" required
                                       class="w-full rounded-lg border-ink-300 py-1.5 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            </td>
                            <td class="px-3 py-2.5 text-center">
                                <input type="hidden" name="affects_cashbox" value="0">
                                <input type="checkbox" name="affects_cashbox" value="1" @checked($method->affects_cashbox)
                                       class="size-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
                            </td>
                            <td class="px-3 py-2.5 text-center">
                                <input type="hidden" name="requires_reference" value="0">
                                <input type="checkbox" name="requires_reference" value="1" @checked($method->requires_reference)
                                       class="size-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
                            </td>
                            <td class="px-3 py-2.5">
                                <select name="status" class="rounded-lg border-ink-300 py-1.5 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                    <option value="active" @selected($method->status === 'active')>نشطة</option>
                                    <option value="inactive" @selected($method->status === 'inactive')>معطّلة</option>
                                </select>
                            </td>
                            <td class="px-3 py-2.5 text-end">
                                <x-ui.button type="submit" variant="secondary" size="sm">حفظ</x-ui.button>
                            </td>
                        </form>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="إضافة طريقة دفع" class="mt-4">
            <form method="POST" action="{{ route('admin.settings.payment-methods.store') }}" class="grid gap-3 sm:grid-cols-4">
                @csrf
                <x-form.input name="code" label="المعرّف" required dir="ltr" />
                <x-form.input name="label_ar" label="الاسم" required />
                <div class="flex flex-col justify-end gap-2">
                    <x-form.checkbox name="affects_cashbox" label="يؤثر على الصندوق" />
                    <x-form.checkbox name="requires_reference" label="يتطلب مرجعاً" />
                </div>
                <div class="flex items-end">
                    <x-ui.button type="submit" size="md">إضافة</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>

    <div x-show="tab === 'expenses'" x-cloak>
        <x-ui.card padded="false" title="تصنيفات المصاريف">
            <x-ui.table :headers="['المعرّف', 'الاسم', 'بند الأرباح والخسائر', 'الحالة', '']">
                @foreach ($expenseCategories as $category)
                    <tr>
                        <form method="POST" action="{{ route('admin.settings.expense-categories.update', $category) }}">
                            @csrf
                            @method('PATCH')
                            <td class="px-3 py-2.5 font-mono text-xs text-ink-500" dir="ltr">
                                {{ $category->code }}
                                @if ($category->is_system)
                                    <x-ui.badge tone="purple">نظام</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-3 py-2.5">
                                <input type="text" name="name_ar" value="{{ $category->name_ar }}" required
                                       class="w-full rounded-lg border-ink-300 py-1.5 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            </td>
                            <td class="px-3 py-2.5">
                                <select name="profit_bucket" class="rounded-lg border-ink-300 py-1.5 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                    @foreach ($buckets as $key => $label)
                                        <option value="{{ $key }}" @selected($category->profit_bucket === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2.5">
                                <select name="status" class="rounded-lg border-ink-300 py-1.5 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                    <option value="active" @selected($category->status === 'active')>نشط</option>
                                    <option value="inactive" @selected($category->status === 'inactive')>معطّل</option>
                                </select>
                            </td>
                            <td class="px-3 py-2.5 text-end">
                                <x-ui.button type="submit" variant="secondary" size="sm">حفظ</x-ui.button>
                            </td>
                        </form>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="إضافة تصنيف مصاريف" class="mt-4">
            <form method="POST" action="{{ route('admin.settings.expense-categories.store') }}" class="grid gap-3 sm:grid-cols-4">
                @csrf
                <x-form.input name="code" label="المعرّف" required dir="ltr" />
                <x-form.input name="name_ar" label="الاسم" required />
                <x-form.select name="profit_bucket" label="بند الأرباح" :options="$buckets" required />
                <div class="flex items-end">
                    <x-ui.button type="submit" size="md">إضافة</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
