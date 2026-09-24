@props(['spec', 'height' => 260])

{{-- The chart config is built server-side and read by resources/js/charts.js,
     which applies the shared palette, axis and tooltip defaults. --}}
<div class="relative w-full" style="height: {{ $height }}px">
    <canvas {{ $attributes }} data-chart="{{ json_encode($spec, JSON_UNESCAPED_UNICODE) }}"></canvas>
</div>
