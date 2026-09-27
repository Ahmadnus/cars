@props([
    // The trainee / trainer / employee record.
    'subject',
    'issueRoute',
    'showRoute' => null,
    'canManage' => true,

    // Roles this record's first account would need. Non-empty only for an
    // employee, where "which roles" cannot be answered by the record itself.
    'needsRoles' => [],
])

{{-- Just a new password, twice.

     The edit page is where staff come to fix a person's details, and "reset the
     password" is the commonest of those — so it is two fields and a button here,
     with none of the account plumbing. Creating an account, choosing roles or
     closing one lives on the person's own page, where there is room to say what
     those things do.

     Its own form, so whoever includes this must place it outside the record's
     form: a form nested in a form posts nothing. --}}
@php $user = $subject->user; @endphp

<x-ui.card title="إعادة تعيين كلمة المرور">
    @if (session('issued_credentials'))
        <x-account.credentials
            :phone="session('issued_credentials')['phone'] ?? null"
            :password="session('issued_credentials')['password'] ?? null"
            :name="$subject->full_name" />
    @elseif (! $canManage)
        <p class="text-xs text-ink-500">لا تملك صلاحية تعديل كلمة المرور لهذا السجل.</p>
    @elseif (! $user && count($needsRoles ?? []))
        {{-- Only an employee reaches this: their first account has to say which
             roles, and that question does not belong on an edit form. --}}
        <p class="text-xs text-ink-500">
            لا يوجد حساب دخول لهذا السجل بعد.
            @if ($showRoute)
                <a href="{{ route($showRoute, $subject) }}" class="font-semibold text-brand-700 hover:underline">
                    أنشئ حساباً من صفحته
                </a>
            @endif
        </p>
    @else
        <form method="POST" action="{{ route($issueRoute, $subject) }}" class="space-y-3">
            @csrf

            <x-form.input
                name="login_password"
                type="password"
                label="كلمة المرور الجديدة"
                autocomplete="new-password"
                dir="ltr"
                :hint="\App\Support\IssuedPassword::hint() . ' اتركها فارغة ليولّدها النظام.'" />

            <x-form.input
                name="login_password_confirmation"
                type="password"
                label="تأكيد كلمة المرور"
                autocomplete="new-password"
                dir="ltr" />

            <x-ui.button type="submit" size="sm" class="w-full">
                {{ $user ? 'إعادة تعيين كلمة المرور' : 'تعيين كلمة المرور' }}
            </x-ui.button>

            <p class="text-[11px] leading-relaxed text-ink-400">
                تظهر كلمة المرور مرة واحدة بعد الحفظ لتسليمها لصاحبها.
                @if ($user)
                    ويُسجَّل خروجه من كل الأجهزة.
                @else
                    يدخل برقم هاتفه: <span class="font-mono" dir="ltr">{{ $subject->phone }}</span>
                @endif
            </p>
        </form>
    @endif
</x-ui.card>
