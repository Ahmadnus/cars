@props([
    'label',
    'value',
    'icon' => null,
    'tone' => 'brand',
    'hint' => null,
    'href' => null,
])

@php
    $tones = [
        'brand' => 'bg-brand-50 text-brand-600',
        'success' => 'bg-emerald-50 text-emerald-600',
        'warning' => 'bg-amber-50 text-amber-600',
        'danger' => 'bg-rose-50 text-rose-600',
        'info' => 'bg-sky-50 text-sky-600',
        'neutral' => 'bg-ink-100 text-ink-500',
    ];

    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => 'block rounded-xl border border-ink-200 bg-white p-4 transition ' . ($href ? 'hover:border-brand-300 hover:shadow-sm' : '')]) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="truncate text-xs text-ink-500">{{ $label }}</p>
            <p class="mt-1.5 text-xl font-semibold tabular-nums text-ink-900">{{ $value }}</p>
            @if ($hint)
                <p class="mt-1 truncate text-xs text-ink-400">{{ $hint }}</p>
            @endif
        </div>
        @if ($icon)
            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ $tones[$tone] ?? $tones['brand'] }}">
                <x-ui.icon :name="$icon" class="size-[18px]" />
            </span>
        @endif
    </div>
</{{ $tag }}>
