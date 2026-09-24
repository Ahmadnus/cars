@extends('layouts.guest')

@section('title', 'استعادة كلمة المرور')

@section('content')
    <h1 class="text-xl font-semibold text-ink-900">استعادة كلمة المرور</h1>
    <p class="mt-1 text-sm text-ink-500">
        أدخل بريدك الإلكتروني وسنرسل لك رابطاً لإعادة تعيين كلمة المرور.
    </p>

    @if (session('status'))
        <x-ui.alert type="success" class="mt-5">{{ session('status') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
        @csrf

        <x-form.input name="email" type="email" label="البريد الإلكتروني" required autofocus dir="ltr" />

        <x-ui.button type="submit" class="w-full" size="lg">إرسال الرابط</x-ui.button>
    </form>

    <a href="{{ route('login') }}" class="mt-6 block text-center text-xs text-brand-600 hover:text-brand-700">
        العودة إلى تسجيل الدخول
    </a>
@endsection
