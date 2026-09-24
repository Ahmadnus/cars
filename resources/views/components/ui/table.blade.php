@props(['headers' => []])

{{-- Horizontal scroll keeps wide admin tables usable on tablet and phone
     without shrinking the type or hiding columns. --}}
<div class="scrollbar-thin -mx-5 overflow-x-auto px-5 sm:mx-0 sm:px-0">
    <table {{ $attributes->merge(['class' => 'w-full min-w-full text-start text-sm']) }}>
        @if (count($headers))
            <thead>
                <tr class="border-b border-ink-200 text-xs text-ink-500">
                    @foreach ($headers as $header)
                        @php
                            $label = is_array($header) ? ($header['label'] ?? '') : $header;
                            $align = is_array($header) ? ($header['align'] ?? 'start') : 'start';
                        @endphp
                        <th scope="col" class="whitespace-nowrap px-3 py-2.5 font-medium text-{{ $align }}">
                            {{ $label }}
                        </th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody class="divide-y divide-ink-100">
            {{ $slot }}
        </tbody>
        @isset($footer)
            <tfoot class="border-t-2 border-ink-200 bg-ink-50 font-semibold">
                {{ $footer }}
            </tfoot>
        @endisset
    </table>
</div>
