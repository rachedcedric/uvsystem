@props(['module', 'summary', 'yesterday', 'daily', 'trend' => null, 'noun', 'nouns', 'canExport' => false])
@php
    use App\Support\Mobile\Fcfa;

    $today = $summary['today'];
@endphp

{{-- Même composition que la page Commissions : carte du jour, rangée de cartes, évolution du mois. --}}
<div class="m-hero" style="margin-top:6px" wire:key="hero-{{ $module }}">
    <div class="m-hero__label">Aujourd'hui</div>
    <div class="m-hero__amount m-num">{{ Fcfa::number($today['amount']) }}<span class="m-hero__unit">FCFA</span></div>
    <div class="m-hero__foot" style="gap:14px;flex-wrap:wrap">
        <span>{{ $today['count'] }} {{ $today['count'] > 1 ? $nouns : $noun }}</span>
        <span>Hier {{ Fcfa::format($yesterday['amount']) }}</span>
    </div>
</div>

<div class="m-strip" data-no-ptr>
    @foreach (['week' => 'calendar-days', 'month' => 'calendar-days', 'all' => 'archive-box'] as $period => $icon)
        @php
            $s = $summary[$period];
        @endphp
        <div class="m-stat" wire:key="stat-{{ $module }}-{{ $period }}">
            <div class="m-stat__label"><x-m.icon :name="$icon" class="m-ico-sm" /><span>{{ $s['label'] }}</span></div>
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

<x-m.section title="Évolution · mois en cours">
    <x-m.daily-chart :days="$daily" :label="'Évolution des '.$nouns.' depuis le début du mois'" />
</x-m.section>

@if ($canExport)
    <x-m.period-export :module="$module" :summary="$summary" :noun="$noun" :nouns="$nouns" />
@endif
