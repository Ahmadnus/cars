@props([
    'name',
    'label' => null,
    'options' => [],
    'selected' => null,
    'placeholder' => null,
    'hint' => null,
    'required' => false,
])

@php
    $hasError = $errors->has($name);
    $selected = old($name, $selected);
@endphp

<x-form.field :label="$label" :name="$name" :hint="$hint" :required="$required">
    <select
        name="{{ $name }}"
        id="{{ $name }}"
        @if ($required) required @endif
        {{ $attributes->merge([
            'class' => 'block w-full rounded-lg text-sm shadow-sm transition '
                . ($hasError
                    ? 'border-rose-300 focus:border-rose-500 focus:ring-rose-500'
                    : 'border-ink-300 focus:border-brand-500 focus:ring-brand-500'),
        ]) }}
    >
        @if ($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif

        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected((string) $selected === (string) $optionValue)>
                {{ $optionLabel }}
            </option>
        @endforeach

        {{ $slot }}
    </select>
</x-form.field>
