@props(['items' => []])

<nav aria-label="مسار التنقل">
    <ol class="flex flex-wrap items-center gap-1.5 text-xs text-ink-500">
        <li>
            <a href="{{ route('admin.dashboard') }}" class="hover:text-brand-600">لوحة التحكم</a>
        </li>

        @foreach ($items as $label => $url)
            <li aria-hidden="true" class="text-ink-300">
                <x-ui.icon name="chevron-left" class="size-3.5 rtl:hidden" />
                <x-ui.icon name="chevron-right" class="size-3.5 ltr:hidden" />
            </li>
            <li @class(['font-medium text-ink-700' => $loop->last])>
                @if ($url && ! $loop->last)
                    <a href="{{ $url }}" class="hover:text-brand-600">{{ $label }}</a>
                @else
                    <span aria-current="page">{{ $label }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
