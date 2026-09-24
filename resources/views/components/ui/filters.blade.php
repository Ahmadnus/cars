@props(['action', 'collapsible' => true])

{{-- Filter bar shared by every list screen.
     Filtering is server-side (a plain GET form) so the browser never has to
     hold a whole branch's dataset to narrow it. --}}
<form method="GET" action="{{ $action }}"
      x-data="{ open: {{ collect(request()->except(['page', 'search']))->filter()->isNotEmpty() ? 'true' : 'false' }} }"
      class="mb-4 rounded-xl border border-ink-200 bg-white">

    <div class="flex flex-wrap items-center gap-2 p-3">
        <label class="relative min-w-0 flex-1">
            <span class="sr-only">بحث</span>
            <x-ui.icon name="search" class="pointer-events-none absolute top-1/2 size-4 -translate-y-1/2 text-ink-400 ltr:left-3 rtl:right-3" />
            <input type="search" name="search" value="{{ request('search') }}"
                   placeholder="{{ $placeholder ?? 'بحث…' }}"
                   class="w-full rounded-lg border-ink-300 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 ltr:pl-9 rtl:pr-9">
        </label>

        @if ($collapsible && isset($fields))
            <x-ui.button type="button" variant="secondary" size="md" icon="filter" @click="open = !open">
                فلاتر
            </x-ui.button>
        @endif

        <x-ui.button type="submit" size="md">تطبيق</x-ui.button>

        @if (collect(request()->except('page'))->filter()->isNotEmpty())
            <x-ui.button :href="$action" variant="ghost" size="md">مسح</x-ui.button>
        @endif

        @isset($actions)
            <div class="ms-auto flex items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>

    @isset($fields)
        <div x-show="open" x-collapse x-cloak class="border-t border-ink-200 p-3">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {{ $fields }}
            </div>
        </div>
    @endisset
</form>
