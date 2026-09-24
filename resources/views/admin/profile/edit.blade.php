@extends('layouts.app')

@section('title', 'الملف الشخصي')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الملف الشخصي' => null]" />
@endsection

@section('content')
<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <x-ui.card title="بياناتي">
            <form method="POST" action="{{ route('admin.profile.update') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                @method('PATCH')
                <x-form.input name="name" label="الاسم" :value="$user->name" required />
                <x-form.input name="email" type="email" label="البريد الإلكتروني" :value="$user->email" required dir="ltr" />
                <x-form.input name="phone" label="رقم الهاتف" :value="$user->phone" dir="ltr" />
                <x-form.select name="locale" label="لغة الواجهة"
                               :options="['ar' => 'العربية', 'en' => 'English']"
                               :selected="$user->locale" required />

                <div class="sm:col-span-2">
                    <x-ui.button type="submit">حفظ البيانات</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card title="تغيير كلمة المرور">
            <form method="POST" action="{{ route('admin.profile.password') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                @method('PATCH')
                <x-form.input name="current_password" type="password" label="كلمة المرور الحالية" required
                              autocomplete="current-password" dir="ltr" class="sm:col-span-2" />
                <x-form.input name="password" type="password" label="كلمة المرور الجديدة" required
                              autocomplete="new-password" dir="ltr" />
                <x-form.input name="password_confirmation" type="password" label="تأكيد كلمة المرور" required
                              autocomplete="new-password" dir="ltr" />

                <div class="sm:col-span-2">
                    <x-ui.button type="submit">تغيير كلمة المرور</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card padded="false" title="سجل الدخول" subtitle="آخر 15 محاولة دخول على حسابك.">
            <x-ui.table :headers="['التاريخ', 'النتيجة', 'المصدر', 'عنوان IP']">
                @foreach ($logins as $login)
                    <tr>
                        <td class="whitespace-nowrap px-3 py-2.5 text-xs text-ink-600">{{ $login->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-2.5">
                            <x-ui.badge :tone="match ($login->result) {
                                'success' => 'success',
                                'failed' => 'danger',
                                'locked' => 'warning',
                                default => 'neutral',
                            }">
                                {{ ['success' => 'ناجح', 'failed' => 'فاشل', 'locked' => 'محظور', 'logout' => 'خروج'][$login->result] ?? $login->result }}
                            </x-ui.badge>
                        </td>
                        <td class="px-3 py-2.5 text-xs text-ink-500">{{ $login->guard === 'sanctum' ? 'تطبيق' : 'المتصفح' }}</td>
                        <td class="px-3 py-2.5 font-mono text-xs text-ink-400" dir="ltr">{{ $login->ip_address }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        @if ($tokens->isNotEmpty())
            <x-ui.card padded="false" title="جلسات التطبيقات" subtitle="رموز الدخول الصادرة لتطبيقات الهاتف.">
                <x-ui.table :headers="['الاسم', 'آخر استخدام', 'تاريخ الإصدار', '']">
                    @foreach ($tokens as $token)
                        <tr>
                            <td class="px-3 py-2.5 text-ink-800">{{ $token->name }}</td>
                            <td class="px-3 py-2.5 text-xs text-ink-500">{{ $token->last_used_at?->diffForHumans() ?? 'لم يُستخدم' }}</td>
                            <td class="px-3 py-2.5 text-xs text-ink-500">{{ $token->created_at->format('Y-m-d') }}</td>
                            <td class="px-3 py-2.5 text-end">
                                <form method="POST" action="{{ route('admin.profile.tokens.revoke', $token->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="ghost" size="sm" class="text-rose-600">إلغاء</x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif
    </div>

    <div class="space-y-5">
        <x-ui.card title="حسابي">
            <div class="mb-4 flex items-center gap-3">
                <span class="flex size-12 items-center justify-center rounded-full bg-brand-50 text-lg font-semibold text-brand-700">
                    {{ $user->initials() }}
                </span>
                <div class="min-w-0">
                    <p class="truncate font-medium text-ink-900">{{ $user->name }}</p>
                    <p class="truncate font-mono text-xs text-ink-400" dir="ltr">{{ $user->email }}</p>
                </div>
            </div>

            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">الأدوار</dt>
                    <dd class="text-end font-medium text-ink-800">{{ $user->roles->pluck('label_ar')->join('، ') ?: '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">الفرع الرئيسي</dt>
                    <dd class="text-ink-800">{{ $user->branch?->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">الفروع المتاحة</dt>
                    <dd class="text-end text-ink-800">
                        {{ $user->canAccessAllBranches() ? 'جميع الفروع' : $user->branches->pluck('name')->join('، ') }}
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">آخر دخول</dt>
                    <dd class="text-xs text-ink-600">{{ $user->last_login_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card title="صلاحياتي" subtitle="{{ $permissions->count() }} صلاحية فعّالة.">
            <details>
                <summary class="cursor-pointer text-sm text-brand-600 hover:underline">عرض القائمة</summary>
                <ul class="mt-2 max-h-72 space-y-1 overflow-y-auto scrollbar-thin">
                    @foreach ($permissions as $permission)
                        <li class="font-mono text-xs text-ink-500" dir="ltr">{{ $permission }}</li>
                    @endforeach
                </ul>
            </details>
        </x-ui.card>
    </div>
</div>
@endsection
