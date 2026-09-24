@extends('layouts.guest')

@section('title', 'إعادة تعيين كلمة المرور')

@section('content')
    <h1 class="text-xl font-semibold text-ink-900">إعادة تعيين كلمة المرور</h1>
    <p class="mt-1 text-sm text-ink-500">اختر كلمة مرور جديدة لحسابك.</p>

    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-form.input name="email" type="email" label="البريد الإلكتروني" :value="$email" required dir="ltr" />
        <x-form.input name="password" type="password" label="كلمة المرور الجديدة" required autocomplete="new-password" dir="ltr" />
        <x-form.input name="password_confirmation" type="password" label="تأكيد كلمة المرور" required autocomplete="new-password" dir="ltr" />

        <x-ui.button type="submit" class="w-full" size="lg">حفظ كلمة المرور</x-ui.button>
    </form>
@endsection
