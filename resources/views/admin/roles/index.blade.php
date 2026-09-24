@extends('layouts.app')

@section('title', 'الأدوار والصلاحيات')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الأدوار والصلاحيات' => null]" />
@endsection

@section('content')
    <x-layout.page-header
        title="الأدوار والصلاحيات"
        description="الأدوار تُجمّع الصلاحيات. الصلاحيات الحساسة مفصولة عن الصلاحيات التشغيلية."
    >
        <x-slot:actions>
            <x-ui.button :href="route('admin.roles.create')" icon="plus">دور جديد</x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($roles as $role)
            <x-ui.card>
                <div class="mb-3 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="truncate font-semibold text-ink-900">{{ $role->label_ar }}</h3>
                        <p class="font-mono text-xs text-ink-400" dir="ltr">{{ $role->name }}</p>
                    </div>
                    @if ($role->is_system)
                        <x-ui.badge tone="purple">دور نظام</x-ui.badge>
                    @endif
                </div>

                @if ($role->description)
                    <p class="mb-3 text-sm text-ink-500">{{ $role->description }}</p>
                @endif

                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">عدد الصلاحيات</dt>
                        <dd class="font-medium tabular-nums text-ink-800">{{ $role->permissions_count }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-500">عدد المستخدمين</dt>
                        <dd class="font-medium tabular-nums text-ink-800">{{ $role->users_count }}</dd>
                    </div>
                </dl>

                <div class="mt-4 flex gap-2 border-t border-ink-100 pt-3">
                    <x-ui.button :href="route('admin.roles.edit', $role)" variant="secondary" size="sm" class="flex-1" icon="edit">
                        تعديل الصلاحيات
                    </x-ui.button>

                    @can('delete', $role)
                        <x-ui.confirm
                            :action="route('admin.roles.destroy', $role)"
                            method="DELETE"
                            title="حذف الدور"
                            message="لا يمكن حذف دور مرتبط بمستخدمين أو دور نظام."
                            confirm-label="حذف"
                        >
                            <x-slot:trigger>
                                <x-ui.button type="button" variant="ghost" size="sm" icon="trash" class="text-rose-600" />
                            </x-slot:trigger>
                        </x-ui.confirm>
                    @endcan
                </div>
            </x-ui.card>
        @endforeach
    </div>
@endsection
