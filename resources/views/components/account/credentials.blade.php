@props([
    'phone' => null,
    'password' => null,
    'email' => null,
    'name' => null,
])

{{-- Credentials the office has just issued, shown once.

     Built for what actually happens next: the receptionist sends them on
     WhatsApp within seconds. So every value is one tap to copy, there is a ready
     WhatsApp link, and the whole message can be copied in one go — reading a
     password off the screen and retyping it into a chat is where a wrong
     character gets in, and the password cannot be looked up again to check. --}}
@php
    $message = collect([
        $name ? 'مرحباً '.$name : null,
        'بيانات الدخول إلى تطبيق '.settings('center.name', config('app.name')).':',
        'رقم الهاتف: '.$phone,
        'كلمة المرور: '.$password,
    ])->filter()->implode("\n");

    // wa.me wants digits only, with a country code. A local 07… number is
    // rewritten to 9627…; anything already international is left alone.
    $waNumber = preg_replace('/\D/', '', (string) $phone);
    $waNumber = str_starts_with($waNumber, '0')
        ? '962'.ltrim($waNumber, '0')
        : $waNumber;
@endphp

<div
    x-data="{
        copied: null,
        copy(value, key) {
            const done = () => { this.copied = key; setTimeout(() => { if (this.copied === key) this.copied = null }, 1800) };

            // The async clipboard API needs a secure context; the textarea
            // fallback keeps this working on a plain-http office machine.
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(value).then(done).catch(() => this.fallback(value, done));
            } else {
                this.fallback(value, done);
            }
        },
        fallback(value, done) {
            const field = document.createElement('textarea');
            field.value = value;
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            try { document.execCommand('copy'); done() } catch (e) { /* nothing to do but leave it on screen */ }
            document.body.removeChild(field);
        },
    }"
    {{ $attributes->merge(['class' => 'rounded-xl border border-emerald-200 bg-emerald-50 p-3.5']) }}
>
    <p class="mb-3 text-xs font-semibold text-emerald-800">
        بيانات الدخول — سلّمها الآن، لن تظهر مرة أخرى.
    </p>

    <div class="space-y-2">
        @if ($phone)
            <div class="flex items-center gap-2 rounded-lg bg-white px-3 py-2">
                <span class="w-20 shrink-0 text-[11px] text-ink-500">رقم الهاتف</span>
                <span class="min-w-0 flex-1 truncate font-mono text-sm font-semibold text-ink-900" dir="ltr">{{ $phone }}</span>
                <button type="button" @click="copy(@js($phone), 'phone')"
                        class="shrink-0 rounded-md px-2 py-1 text-[11px] font-semibold text-brand-700 hover:bg-brand-50">
                    <span x-show="copied !== 'phone'">نسخ</span>
                    <span x-show="copied === 'phone'" x-cloak class="text-emerald-700">تم النسخ ✓</span>
                </button>
            </div>
        @endif

        @if ($password)
            <div class="flex items-center gap-2 rounded-lg bg-white px-3 py-2">
                <span class="w-20 shrink-0 text-[11px] text-ink-500">كلمة المرور</span>
                <span class="min-w-0 flex-1 truncate font-mono text-base font-bold tracking-wide text-ink-900" dir="ltr">{{ $password }}</span>
                <button type="button" @click="copy(@js($password), 'password')"
                        class="shrink-0 rounded-md px-2 py-1 text-[11px] font-semibold text-brand-700 hover:bg-brand-50">
                    <span x-show="copied !== 'password'">نسخ</span>
                    <span x-show="copied === 'password'" x-cloak class="text-emerald-700">تم النسخ ✓</span>
                </button>
            </div>
        @endif

        @if ($email)
            <div class="flex items-center gap-2 rounded-lg bg-white px-3 py-2">
                <span class="w-20 shrink-0 text-[11px] text-ink-500">البريد</span>
                <span class="min-w-0 flex-1 truncate font-mono text-xs text-ink-700" dir="ltr">{{ $email }}</span>
                <button type="button" @click="copy(@js($email), 'email')"
                        class="shrink-0 rounded-md px-2 py-1 text-[11px] font-semibold text-brand-700 hover:bg-brand-50">
                    <span x-show="copied !== 'email'">نسخ</span>
                    <span x-show="copied === 'email'" x-cloak class="text-emerald-700">تم النسخ ✓</span>
                </button>
            </div>
        @endif
    </div>

    <div class="mt-3 flex flex-wrap gap-2">
        @if ($waNumber)
            <a href="https://wa.me/{{ $waNumber }}?text={{ urlencode($message) }}"
               target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">
                إرسال واتساب
            </a>
        @endif

        <button type="button" @click="copy(@js($message), 'all')"
                class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-50">
            <span x-show="copied !== 'all'">نسخ الرسالة كاملة</span>
            <span x-show="copied === 'all'" x-cloak>تم نسخ الرسالة ✓</span>
        </button>

        @if ($phone)
            <a href="tel:{{ $phone }}"
               class="inline-flex items-center gap-1.5 rounded-lg border border-ink-200 bg-white px-3 py-1.5 text-xs font-semibold text-ink-600 hover:bg-ink-50">
                اتصال
            </a>
        @endif
    </div>
</div>
