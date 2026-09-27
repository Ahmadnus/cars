@props([
    // Roles to choose from. Empty for a trainee or trainer, whose role follows
    // from what they are rather than from a checkbox someone might mis-tick.
    'roles' => [],
    'title' => 'حساب الدخول',
    'hint' => null,
    'checked' => true,
])

{{-- The login fields on an "add person" form.
     One component for trainees, trainers and employees: the fields are the same
     three questions, and three copies is three places for a weak password to
     get in through the one that was not updated. --}}
<x-ui.card :title="$title">
    <div x-data="{ open: {{ old('create_login', $checked) ? 'true' : 'false' }} }" class="space-y-3">
        <x-form.checkbox
            name="create_login"
            :checked="$checked"
            label="إنشاء حساب دخول"
            :hint="$hint ?? 'يدخل صاحب الحساب برقم هاتفه أو بريده الإلكتروني.'"
            x-model="open" />

        <div x-show="open" x-cloak class="space-y-3 border-t border-ink-100 pt-3">
            <x-form.input
                name="login_email"
                type="email"
                label="البريد الإلكتروني (اختياري)"
                dir="ltr"
                autocomplete="off"
                hint="اتركه فارغاً إذا كان الدخول برقم الهاتف فقط." />

            {{-- Typed by the member of staff, because they are usually about to
                 read it out on the phone. Left blank, the system generates one. --}}
            <x-form.input
                name="login_password"
                type="password"
                label="كلمة المرور"
                autocomplete="new-password"
                hint="اتركها فارغة ليولّدها النظام. تظهر مرة واحدة بعد الحفظ." />

            <x-form.input
                name="login_password_confirmation"
                type="password"
                label="تأكيد كلمة المرور"
                autocomplete="new-password" />

            @if (count($roles))
                <div>
                    <p class="mb-1.5 text-sm text-ink-700">الأدوار <span class="text-rose-500">*</span></p>
                    <p class="mb-2 text-xs text-ink-400">تحدّد ما يستطيع الموظف رؤيته في لوحة التحكم.</p>
                    <div class="grid gap-1.5 sm:grid-cols-2">
                        @foreach ($roles as $id => $label)
                            <label class="flex items-center gap-2 text-sm text-ink-700">
                                <input type="checkbox" name="login_roles[]" value="{{ $id }}"
                                       @checked(in_array((string) $id, (array) old('login_roles', []), true))
                                       class="size-4 rounded border-ink-300 text-brand-600">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @error('login_roles')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>
            @endif
        </div>
    </div>
</x-ui.card>
