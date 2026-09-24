@php
    $user ??= null;
    $roles ??= collect();
    $branches ??= [];
    $selectedRoles = old('roles', $user?->roles->pluck('id')->all() ?? []);
    $selectedBranches = old('branches', $user?->branches->pluck('id')->all() ?? []);
@endphp

<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <x-ui.card title="بيانات الحساب">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input name="name" label="الاسم" :value="$user?->name" required />
                <x-form.input name="email" type="email" label="البريد الإلكتروني" :value="$user?->email" required dir="ltr" />
                <x-form.input name="phone" label="رقم الهاتف" :value="$user?->phone" dir="ltr" />
                <x-form.select name="status" label="الحالة"
                               :options="['active' => 'نشط', 'inactive' => 'معطّل', 'suspended' => 'موقوف']"
                               :selected="$user?->status ?? 'active'" required />
                <x-form.input name="password" type="password" label="كلمة المرور" :required="! $user" autocomplete="new-password"
                              :hint="$user ? 'اتركها فارغة للإبقاء على كلمة المرور الحالية.' : null" dir="ltr" />
                <x-form.input name="password_confirmation" type="password" label="تأكيد كلمة المرور"
                              :required="! $user" autocomplete="new-password" dir="ltr" />
            </div>
        </x-ui.card>

        <x-ui.card title="الأدوار">
            <div class="space-y-2">
                @foreach ($roles as $role)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-200 p-3 transition has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/50">
                        <input type="checkbox" name="roles[]" value="{{ $role->id }}"
                               @checked(in_array($role->id, $selectedRoles))
                               class="mt-0.5 size-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
                        <span class="min-w-0">
                            <span class="block font-medium text-ink-900">{{ $role->label_ar }}</span>
                            @if ($role->description)
                                <span class="block text-xs text-ink-500">{{ $role->description }}</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-ink-400">الصلاحيات الفعلية هي اتحاد صلاحيات كل الأدوار المختارة.</p>
        </x-ui.card>

        <x-ui.card title="الفروع المتاحة">
            @if (auth()->user()->isSuperAdmin())
                <x-form.checkbox name="can_access_all_branches" label="الوصول إلى جميع الفروع"
                                 :checked="$user?->can_access_all_branches ?? false"
                                 hint="يتجاوز قائمة الفروع أدناه." class="mb-3" />
            @endif

            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($branches as $id => $name)
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-ink-200 px-3 py-2 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                        <input type="checkbox" name="branches[]" value="{{ $id }}"
                               @checked(in_array($id, $selectedBranches))
                               class="size-3.5 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
                        {{ $name }}
                    </label>
                @endforeach
            </div>
        </x-ui.card>
    </div>

    <div class="space-y-5">
        <x-ui.card title="الفرع الرئيسي">
            <x-form.select name="branch_id" label="الفرع" :options="$branches"
                           :selected="$user?->branch_id ?? branch_context()->currentId()" required />
        </x-ui.card>

        <x-ui.card>
            <div class="flex flex-col gap-2">
                <x-ui.button type="submit" size="lg">{{ $user ? 'حفظ التعديلات' : 'إنشاء المستخدم' }}</x-ui.button>
                <x-ui.button :href="route('admin.users.index')" variant="secondary">إلغاء</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
