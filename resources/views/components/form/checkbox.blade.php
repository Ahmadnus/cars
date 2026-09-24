@props([
    'name',
    'label' => null,
    'checked' => false,
    'value' => 1,
    'hint' => null,
])

@php
    $checked = (bool) old($name, $checked);
@endphp

<label class="flex items-start gap-2.5">
    {{-- Paired hidden input so an unchecked box posts a falsy value rather
         than disappearing from the request entirely. --}}
    <input type="hidden" name="{{ $name }}" value="0">
    <input
        type="checkbox"
        name="{{ $name }}"
        id="{{ $name }}"
        value="{{ $value }}"
        @checked($checked)
        {{ $attributes->merge(['class' => 'mt-0.5 size-4 rounded border-ink-300 text-brand-600 shadow-sm focus:ring-brand-500']) }}
    >
    <span class="min-w-0">
        @if ($label)<span class="block text-sm text-ink-700">{{ $label }}</span>@endif
        @if ($hint)<span class="block text-xs text-ink-400">{{ $hint }}</span>@endif
    </span>
</label>
