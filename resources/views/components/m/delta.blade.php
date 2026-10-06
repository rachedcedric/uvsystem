@props(['trend', 'pct', 'good' => 'up', 'suffix' => 'vs hier'])
@php
    $tone = $trend === 'stable' ? 'flat' : ($trend === $good ? 'up' : 'down');
    $icon = match ($trend) { 'up' => 'arrow-trending-up', 'down' => 'arrow-trending-down', default => 'minus' };
    $sign = $pct > 0 ? '+' : ($pct < 0 ? '−' : '');
@endphp
<span class="m-delta m-delta--{{ $tone }}">
    <x-m.icon :name="$icon" solid class="m-ico-sm" style="width:14px;height:14px" />
    {{ $sign }}{{ number_format(abs($pct), 1, ',', '') }} %
    @if ($suffix) <span style="font-weight:500;opacity:.8">{{ $suffix }}</span> @endif
</span>
