@extends('pdf._layout')

@section('content')
    <div class="doc-title">{{ $title }}</div>

    @if (! empty($meta))
        <p class="muted" style="margin-bottom: 10px;">
            @foreach ($meta as $label => $value)
                {{ $label }}: {{ $value }}@if (! $loop->last) &nbsp;·&nbsp; @endif
            @endforeach
        </p>
    @endif

    <table class="data">
        <thead>
            <tr>
                @foreach ($columns as $key => $column)
                    <th @class(['num' => in_array($column['type'] ?? 'text', ['money', 'number'], true)])>
                        {{ $column['label'] }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($columns as $key => $column)
                        @php
                            $value = $row[$key] ?? null;
                            $type = $column['type'] ?? 'text';
                        @endphp
                        <td @class(['num' => in_array($type, ['money', 'number'], true)])>
                            @if ($type === 'money')
                                {{ number_format((float) $value, 2) }}
                            @elseif ($type === 'date')
                                {{ $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d') : '—' }}
                            @else
                                {{ $value ?? '—' }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columns) }}" style="text-align: center; color: #98a2a8; padding: 20px;">
                        لا توجد بيانات ضمن الفلاتر المحددة.
                    </td>
                </tr>
            @endforelse
        </tbody>

        @if (! empty($totals))
            <tfoot>
                <tr>
                    @foreach ($columns as $key => $column)
                        <td @class(['num' => isset($totals[$key])])>
                            @if ($loop->first)
                                الإجمالي ({{ count($rows) }} سجل)
                            @elseif (isset($totals[$key]))
                                {{ ($column['type'] ?? '') === 'money'
                                    ? number_format($totals[$key], 2)
                                    : number_format($totals[$key]) }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>
@endsection
