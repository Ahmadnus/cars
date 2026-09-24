@props(['type' => 'info', 'title' => null])

@php
    $styles = [
        'success' => ['bg-emerald-50 text-emerald-800 ring-emerald-200', 'check'],
        'error' => ['bg-rose-50 text-rose-800 ring-rose-200', 'alert'],
        'warning' => ['bg-amber-50 text-amber-800 ring-amber-200', 'alert'],
        'info' => ['bg-sky-50 text-sky-800 ring-sky-200', 'info'],
    ];

    [$classes, $icon] = $styles[$type] ?? $styles['info'];
@endphp

<div {{ $attributes->merge(['class' => 'flex gap-3 rounded-xl px-4 py-3 text-sm ring-1 ring-inset ' . $classes]) }}
     role="{{ $type === 'error' ? 'alert' : 'status' }}">
    <x-ui.icon :name="$icon" class="mt-0.5 size-4 shrink-0" />
    <div class="min-w-0 flex-1">
        @if ($title)<p class="mb-1 font-semibold">{{ $title }}</p>@endif
        {{ $slot }}
    </div>
</div>
