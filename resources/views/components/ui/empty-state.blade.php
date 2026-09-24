@props(['icon' => 'inbox', 'title' => 'لا توجد بيانات', 'description' => null])

<div class="flex flex-col items-center justify-center px-6 py-14 text-center">
    <span class="mb-3 flex size-12 items-center justify-center rounded-full bg-ink-100 text-ink-400">
        <x-ui.icon :name="$icon" class="size-6" />
    </span>
    <p class="text-sm font-medium text-ink-700">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 max-w-sm text-xs text-ink-400">{{ $description }}</p>
    @endif
    @isset($action)
        <div class="mt-4">{{ $action }}</div>
    @endisset
</div>
