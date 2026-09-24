@props([
    'name',
    'title' => null,
    'maxWidth' => 'lg',
])

@php
    $widths = ['sm' => 'max-w-sm', 'md' => 'max-w-md', 'lg' => 'max-w-lg', 'xl' => 'max-w-xl', '2xl' => 'max-w-2xl'];
@endphp

<div
    x-data="{ open: false }"
    x-on:open-modal.window="if ($event.detail === '{{ $name }}') open = true"
    x-on:close-modal.window="if ($event.detail === '{{ $name }}') open = false"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto p-4 sm:items-center"
    role="dialog"
    aria-modal="true"
>
    <div x-show="open" x-transition.opacity @click="open = false" class="fixed inset-0 bg-ink-900/50"></div>

    <div x-show="open" x-transition
         class="relative w-full {{ $widths[$maxWidth] ?? $widths['lg'] }} overflow-hidden rounded-2xl bg-white shadow-xl">
        @if ($title)
            <header class="flex items-center justify-between border-b border-ink-200 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-ink-900">{{ $title }}</h2>
                <button type="button" @click="open = false" class="rounded-md p-1 text-ink-400 hover:bg-ink-100">
                    <x-ui.icon name="x" class="size-4" />
                </button>
            </header>
        @endif

        <div class="p-5">{{ $slot }}</div>

        @isset($footer)
            <footer class="flex items-center justify-end gap-2 border-t border-ink-200 bg-ink-50 px-5 py-3">
                {{ $footer }}
            </footer>
        @endisset
    </div>
</div>
