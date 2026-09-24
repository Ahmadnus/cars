@extends('layouts.app')

@section('title', 'تعديل المستخدم')
@section('subtitle', $user->name)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المستخدمون' => route('admin.users.index'), $user->name => null]" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.users.update', $user) }}">
        @csrf
        @method('PATCH')
        @include('admin.users._form', compact('user', 'roles', 'branches'))
    </form>

    @canDo('roles.manage')
        {{-- Per-user overrides sit on top of the role grants, for the one-off
             case where someone needs slightly more or less than their role. --}}
        <x-ui.card title="صلاحيات خاصة بالمستخدم" class="mt-5"
                   subtitle="استثناءات تُضاف فوق صلاحيات الأدوار أو تُلغي منها.">
            <form method="POST" action="{{ route('admin.users.permissions', $user) }}">
                @csrf
                @method('PATCH')

                @php
                    $granted = $user->permissionOverrides->filter(fn ($p) => $p->pivot->granted)->pluck('name')->all();
                    $revoked = $user->permissionOverrides->reject(fn ($p) => $p->pivot->granted)->pluck('name')->all();
                    $fromRoles = $user->roles->flatMap(fn ($r) => $r->permissions->pluck('name'))->unique();
                @endphp

                <div class="space-y-5">
                    @foreach ($permissionCatalog as $groupKey => $group)
                        <div>
                            <p class="mb-2 text-sm font-semibold text-ink-700">{{ $group['label'] }}</p>
                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($group['permissions'] as $name => [$label, $sensitive])
                                    @php $viaRole = $fromRoles->contains($name); @endphp
                                    <div class="rounded-lg border border-ink-200 p-2.5">
                                        <p class="mb-1.5 flex items-center gap-1.5 text-xs text-ink-700">
                                            {{ $label }}
                                            @if ($sensitive)<x-ui.badge tone="danger">حساسة</x-ui.badge>@endif
                                        </p>
                                        <p class="mb-1.5 text-[11px] text-ink-400">
                                            {{ $viaRole ? 'ممنوحة عبر الدور' : 'غير ممنوحة عبر الدور' }}
                                        </p>
                                        <div class="flex gap-3 text-xs">
                                            <label class="flex items-center gap-1">
                                                <input type="checkbox" name="granted[]" value="{{ $name }}"
                                                       @checked(in_array($name, $granted))
                                                       class="size-3.5 rounded border-ink-300 text-emerald-600 focus:ring-emerald-500">
                                                منح
                                            </label>
                                            <label class="flex items-center gap-1">
                                                <input type="checkbox" name="revoked[]" value="{{ $name }}"
                                                       @checked(in_array($name, $revoked))
                                                       class="size-3.5 rounded border-ink-300 text-rose-600 focus:ring-rose-500">
                                                سحب
                                            </label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <x-ui.button type="submit" class="mt-5">حفظ الصلاحيات الخاصة</x-ui.button>
            </form>
        </x-ui.card>
    @endcanDo
@endsection
