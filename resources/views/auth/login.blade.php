@extends('layouts.guest')

@section('title', 'تسجيل الدخول')

@section('content')
    <div class="mb-8 lg:hidden">
        <span class="flex size-11 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold text-white">
            {{ mb_substr(config('app.name'), 0, 1) }}
        </span>
    </div>

    <h1 class="text-xl font-semibold text-ink-900">تسجيل الدخول</h1>
    <p class="mt-1 text-sm text-ink-500">أدخل بياناتك للوصول إلى لوحة التحكم.</p>

    @if (session('status'))
        <x-ui.alert type="success" class="mt-5">{{ session('status') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
        @csrf

        <x-form.input
            name="email"
            type="email"
            label="البريد الإلكتروني"
            required
            autofocus
            autocomplete="username"
            placeholder="name@example.com"
            dir="ltr"
        />

        <x-form.input
            name="password"
            type="password"
            label="كلمة المرور"
            required
            autocomplete="current-password"
            dir="ltr"
        />

        <div class="flex items-center justify-between gap-3">
            <x-form.checkbox name="remember" label="تذكرني" />

            <a href="{{ route('password.request') }}" class="text-xs text-brand-600 hover:text-brand-700">
                نسيت كلمة المرور؟
            </a>
        </div>

        <x-ui.button type="submit" class="w-full" size="lg">تسجيل الدخول</x-ui.button>
    </form>

    @if (! app()->isProduction())
        {{-- Development convenience only; the seeder documents these accounts
             and they must never exist in production. --}}
        <div class="mt-8 rounded-xl border border-dashed border-ink-300 bg-white p-4">
            <p class="mb-2 text-xs font-semibold text-ink-600">حسابات تجريبية (بيئة التطوير فقط)</p>
            <dl class="space-y-1 text-xs text-ink-500">
                @foreach ([
                    'مدير النظام' => 'admin@example.com',
                    'مدير المركز' => 'manager@example.com',
                    'محاسب' => 'accountant@example.com',
                    'موظف استقبال' => 'reception@example.com',
                    'مشرف تدريب' => 'supervisor@example.com',
                    'مدرب' => 'trainer@example.com',
                ] as $role => $email)
                    <div class="flex items-center justify-between gap-3">
                        <dt>{{ $role }}</dt>
                        <dd class="font-mono" dir="ltr">{{ $email }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-2 border-t border-ink-200 pt-2 text-xs text-ink-500">
                كلمة المرور للجميع: <span class="font-mono" dir="ltr">password123</span>
            </p>
        </div>
    @endif
@endsection
