@extends('layouts.app')

@section('title', 'المستخدمون')
@section('subtitle', $users->total() . ' مستخدم')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المستخدمون' => null]" />
@endsection

@section('content')
    <x-layout.page-header title="المستخدمون" description="حسابات الدخول إلى النظام وأدوارها.">
        <x-slot:actions>
            <x-ui.button :href="route('admin.roles.index')" variant="secondary" icon="key">الأدوار</x-ui.button>
            <x-ui.button :href="route('admin.users.create')" icon="plus">مستخدم جديد</x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <x-ui.filters :action="route('admin.users.index')" placeholder="ابحث بالاسم أو البريد الإلكتروني…">
        <x-slot:fields>
            <x-form.select name="role_id" label="الدور" :options="$roles" :selected="request('role_id')" placeholder="كل الأدوار" />
            <x-form.select name="status" label="الحالة"
                           :options="['active' => 'نشط', 'inactive' => 'معطّل', 'suspended' => 'موقوف']"
                           :selected="request('status')" placeholder="كل الحالات" />
        </x-slot:fields>
    </x-ui.filters>

    <x-ui.card padded="false">
        @if ($users->isEmpty())
            <x-ui.empty-state icon="user-cog" title="لا يوجد مستخدمون مطابقون" />
        @else
            <x-ui.table :headers="['المستخدم', 'الأدوار', 'الفرع', 'آخر دخول', 'الحالة', ['label' => '', 'align' => 'end']]">
                @foreach ($users as $user)
                    <tr class="hover:bg-ink-50">
                        <td class="px-3 py-3">
                            <div class="flex items-center gap-3">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700">
                                    {{ $user->initials() }}
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-ink-900">
                                        {{ $user->name }}
                                        @if ($user->isSuperAdmin())
                                            <x-ui.badge tone="purple">مدير النظام</x-ui.badge>
                                        @endif
                                    </span>
                                    <span class="block truncate font-mono text-xs text-ink-400" dir="ltr">{{ $user->email }}</span>
                                </span>
                            </div>
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex flex-wrap gap-1">
                                @foreach ($user->roles as $role)
                                    <x-ui.badge tone="brand">{{ $role->label_ar }}</x-ui.badge>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-3 py-3 text-ink-600">
                            {{ $user->can_access_all_branches ? 'كل الفروع' : ($user->branch?->name ?? '—') }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-xs text-ink-500">
                            {{ $user->last_login_at?->format('Y-m-d H:i') ?? 'لم يسجّل الدخول' }}
                        </td>
                        <td class="px-3 py-3"><x-ui.status type="person" :value="$user->status" /></td>
                        <td class="px-3 py-3 text-end">
                            <div class="flex justify-end gap-1">
                                <x-ui.button :href="route('admin.users.edit', $user)" variant="ghost" size="sm" icon="edit" />

                                @can('delete', $user)
                                    <x-ui.confirm
                                        :action="route('admin.users.destroy', $user)"
                                        method="DELETE"
                                        title="تعطيل الحساب"
                                        message="سيتم تعطيل الحساب وإلغاء جميع جلساته ورموز الدخول. لن يُحذف المستخدم حفاظاً على سجل التدقيق."
                                        confirm-label="تعطيل"
                                    >
                                        <x-slot:trigger>
                                            <x-ui.button type="button" variant="ghost" size="sm" icon="x" class="text-rose-600" />
                                        </x-slot:trigger>
                                    </x-ui.confirm>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $users->links() }}</div>
@endsection
