@props(['tone' => 'neutral', 'dot' => false])

@php
    $tones = [
        'neutral' => 'bg-ink-100 text-ink-700 ring-ink-200',
        'brand' => 'bg-brand-50 text-brand-700 ring-brand-200',
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'warning' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'danger' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'info' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'purple' => 'bg-violet-50 text-violet-700 ring-violet-200',
    ];

    $dots = [
        'neutral' => 'bg-ink-400', 'brand' => 'bg-brand-500', 'success' => 'bg-emerald-500',
        'warning' => 'bg-amber-500', 'danger' => 'bg-rose-500', 'info' => 'bg-sky-500',
        'purple' => 'bg-violet-500',
    ];
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset '
        . ($tones[$tone] ?? $tones['neutral']),
]) }}>
    @if ($dot)
        <span class="size-1.5 rounded-full {{ $dots[$tone] ?? $dots['neutral'] }}"></span>
    @endif
    {{ $slot }}
</span>
