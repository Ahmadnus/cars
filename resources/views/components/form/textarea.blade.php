@props([
    'name',
    'label' => null,
    'value' => null,
    'rows' => 3,
    'hint' => null,
    'required' => false,
])

@php
    $hasError = $errors->has($name);
    $value = old($name, $value);
@endphp

<x-form.field :label="$label" :name="$name" :hint="$hint" :required="$required">
    <textarea
        name="{{ $name }}"
        id="{{ $name }}"
        rows="{{ $rows }}"
        @if ($required) required @endif
        {{ $attributes->merge([
            'class' => 'block w-full rounded-lg text-sm shadow-sm transition '
                . ($hasError
                    ? 'border-rose-300 focus:border-rose-500 focus:ring-rose-500'
                    : 'border-ink-300 focus:border-brand-500 focus:ring-brand-500'),
        ]) }}
    >{{ $value }}</textarea>
</x-form.field>
