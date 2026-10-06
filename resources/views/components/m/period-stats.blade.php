@props(['module', 'summary', 'trend' => null, 'noun', 'nouns', 'canExport' => false])
@php
    use App\Support\Mobile\Fcfa;

    $icons = ['today' => 'calendar', 'week' => 'calendar-days', 'month' => 'calendar-days', 'all' => 'archive-box'];
@endphp

<div class="m-grid" data-no-ptr>
    @foreach ($summary as $period => $s)
        <div class="m-stat" wire:key="stat-{{ $module }}-{{ $period }}">
            <div class="m-stat__label"><x-m.icon :name="$icons[$period]" class="m-ico-sm" /><span>{{ $s['label'] }}</span></div>
            <div class="m-stat__value m-num">{{ Fcfa::number($s['amount']) }}<span class="m-stat__unit"> FCFA</span></div>
            <div class="m-stat__sub">
                {{ $s['count'] }} {{ $s['count'] > 1 ? $nouns : $noun }}
                @if ($period === 'week' && $trend !== null)
                    <x-m.delta :trend="$trend > 0 ? 'up' : ($trend < 0 ? 'down' : 'stable')" :pct="$trend" suffix="" />
                @endif
            </div>
        </div>
    @endforeach
</div>

@if ($canExport)
    <x-m.period-export :module="$module" :summary="$summary" :noun="$noun" :nouns="$nouns" />
@endif
