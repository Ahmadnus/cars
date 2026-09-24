@props([
    'label' => null,
    'name' => null,
    'hint' => null,
    'required' => false,
    'error' => null,
])

@php
    $error ??= $name ? $errors->first($name) : null;
@endphp

<div {{ $attributes->merge(['class' => 'space-y-1.5']) }}>
    @if ($label)
        <label @if ($name) for="{{ $name }}" @endif class="block text-sm font-medium text-ink-700">
            {{ $label }}
            @if ($required)<span class="text-rose-500">*</span>@endif
        </label>
    @endif

    {{ $slot }}

    @if ($error)
        <p class="text-xs text-rose-600">{{ $error }}</p>
    @elseif ($hint)
        <p class="text-xs text-ink-400">{{ $hint }}</p>
    @endif
</div>
