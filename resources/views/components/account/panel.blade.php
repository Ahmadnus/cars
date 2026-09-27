@props([
    // The trainee / trainer / employee record.
    'subject',
    'issueRoute',
    'suspendRoute',
    'roles' => [],
    'canManage' => true,
    'title' => 'حساب الدخول',
])

{{-- The login on a person's page: create it, reset the password, or close it.
     Administered from the record the office is already looking at, because the
     password has to be read out to the person whose page this is. --}}
@php $user = $subject->user; @endphp

<x-ui.card :title="$title">
    @if (session('issued_credentials'))
        {{-- Shown once: nothing keeps the password readable, so a lost one is
             reset rather than looked up. --}}
        <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
            <p class="mb-2 text-xs font-semibold text-emerald-800">
                سلّم هذه البيانات لصاحب الحساب الآن — لن تظهر مرة أخرى.
            </p>
            <dl class="space-y-1 text-xs">
                <div>
                    <dt class="inline text-ink-500">رقم الهاتف:</dt>
                    <dd class="inline font-mono text-ink-900" dir="ltr">{{ session('issued_credentials')['phone'] }}</dd>
                </div>
                @if (session('issued_credentials')['email'] ?? null)
                    <div>
                        <dt class="inline text-ink-500">البريد:</dt>
                        <dd class="inline font-mono text-ink-900" dir="ltr">{{ session('issued_credentials')['email'] }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="inline text-ink-500">كلمة المرور:</dt>
                    <dd class="inline font-mono text-sm font-bold text-ink-900" dir="ltr">{{ session('issued_credentials')['password'] }}</dd>
                </div>
            </dl>
        </div>
    @endif

    @if ($user)
        <dl class="mb-3 space-y-1.5 text-xs">
            <div>
                <dt class="inline text-ink-500">الحالة:</dt>
                <dd class="inline">
                    @if ($user->status === 'active')
                        <x-ui.badge tone="success">مفعّل</x-ui.badge>
                    @else
                        <x-ui.badge tone="danger">{{ $user->status === 'suspended' ? 'معطّل' : 'غير مفعّل' }}</x-ui.badge>
                    @endif
                </dd>
            </div>
            @if ($user->phone)
                <div>
                    <dt class="inline text-ink-500">الدخول بالهاتف:</dt>
                    <dd class="inline font-mono text-ink-800" dir="ltr">{{ $user->phone }}</dd>
                </div>
            @endif
            <div>
                <dt class="inline text-ink-500">البريد:</dt>
                <dd class="inline font-mono text-ink-800" dir="ltr">{{ $user->email }}</dd>
            </div>
            @if ($user->relationLoaded('roles') && $user->roles->isNotEmpty())
                <div>
                    <dt class="inline text-ink-500">الأدوار:</dt>
                    <dd class="inline text-ink-800">{{ $user->roles->pluck('label_ar')->join('، ') }}</dd>
                </div>
            @endif
        </dl>
    @else
        <p class="mb-3 text-xs text-ink-500">
            لا يوجد حساب دخول. أنشئ حساباً ليتمكن صاحبه من الدخول برقم هاتفه
            {{ count($roles) ? 'إلى لوحة التحكم' : 'إلى التطبيق' }}.
        </p>
    @endif

    @if ($canManage)
        <div x-data="{ open: false }">
            <div x-show="! open">
                <x-ui.button size="sm" @click="open = true" :variant="$user ? 'secondary' : 'primary'" class="w-full">
                    {{ $user ? 'تعيين كلمة مرور جديدة' : 'إنشاء حساب دخول' }}
                </x-ui.button>
            </div>

            <form x-show="open" x-cloak method="POST"
                  action="{{ route($issueRoute, $subject) }}"
                  class="space-y-2.5 rounded-lg border border-ink-200 p-3">
                @csrf

                @unless ($user)
                    <x-form.input name="login_email" type="email" label="البريد الإلكتروني (اختياري)" dir="ltr" />
                @endunless

                <x-form.input
                    name="login_password"
                    type="password"
                    label="كلمة المرور"
                    autocomplete="new-password"
                    hint="اتركها فارغة ليولّدها النظام." />

                <x-form.input
                    name="login_password_confirmation"
                    type="password"
                    label="تأكيد كلمة المرور"
                    autocomplete="new-password" />

                @if (count($roles))
                    <div>
                        <p class="mb-1.5 text-xs text-ink-600">الأدوار</p>
                        <div class="space-y-1">
                            @foreach ($roles as $id => $label)
                                <label class="flex items-center gap-2 text-xs text-ink-700">
                                    <input type="checkbox" name="login_roles[]" value="{{ $id }}"
                                           @checked($user && $user->relationLoaded('roles') && $user->roles->contains('id', $id))
                                           class="size-4 rounded border-ink-300 text-brand-600">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex gap-2">
                    <x-ui.button size="sm" type="submit" class="flex-1">حفظ</x-ui.button>
                    <x-ui.button size="sm" variant="secondary" type="button" @click="open = false">إلغاء</x-ui.button>
                </div>
            </form>
        </div>

        @if ($user && $user->status === 'active')
            <form method="POST" action="{{ route($suspendRoute, $subject) }}" class="mt-2">
                @csrf
                <x-ui.button size="sm" type="submit" variant="danger" class="w-full">تعطيل الحساب</x-ui.button>
            </form>
        @endif
    @endif
</x-ui.card>
