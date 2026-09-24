@props(['title' => null, 'description' => null])

<div {{ $attributes->merge(['class' => 'mb-5 flex flex-wrap items-end justify-between gap-3']) }}>
    <div class="min-w-0">
        @if ($title)
            <h2 class="text-lg font-semibold text-ink-900">{{ $title }}</h2>
        @endif
        @if ($description)
            <p class="mt-0.5 text-sm text-ink-500">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
