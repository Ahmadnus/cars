@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'required' => false,
    'prefix' => null,
])

@php
    $hasError = $errors->has($name);
    $value = old($name, $value);
@endphp

<x-form.field :label="$label" :name="$name" :hint="$hint" :required="$required">
    <div class="relative">
        @if ($prefix)
            <span class="pointer-events-none absolute inset-y-0 flex items-center text-xs text-ink-400 ltr:left-3 rtl:right-3">
                {{ $prefix }}
            </span>
        @endif

        <input
            type="{{ $type }}"
            name="{{ $name }}"
            id="{{ $name }}"
            value="{{ $value }}"
            @if ($required) required @endif
            {{ $attributes->merge([
                'class' => 'block w-full rounded-lg text-sm shadow-sm transition '
                    . ($prefix ? 'ltr:pl-12 rtl:pr-12 ' : '')
                    . ($hasError
                        ? 'border-rose-300 text-rose-900 focus:border-rose-500 focus:ring-rose-500'
                        : 'border-ink-300 focus:border-brand-500 focus:ring-brand-500'),
            ]) }}
        >
    </div>
</x-form.field>
