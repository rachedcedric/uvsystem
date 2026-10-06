@php
    use App\Services\Mobile\MonthOverview;
    use App\Support\Mobile\Fcfa;
@endphp
<div>
    @if ($showCharges && $revenue)
        <x-m.section title="Revenu du mois">
            <div class="m-strip" data-no-ptr>
                <a href="{{ route('m.charges') }}" wire:navigate class="m-stat" data-haptic>
                    <div class="m-stat__label"><x-m.icon name="banknotes" class="m-ico-sm" /><span>Total revenu</span></div>
                    <div class="m-stat__value m-num" style="color:var(--ok)">{{ Fcfa::number($revenue['revenue']) }}<span class="m-stat__unit"> FCFA</span></div>
                </a>
                <a href="{{ route('m.charges') }}" wire:navigate class="m-stat" data-haptic>
                    <div class="m-stat__label"><x-m.icon name="receipt-percent" class="m-ico-sm" /><span>Total charges</span></div>
                    <div class="m-stat__value m-num" style="color:var(--danger)">{{ Fcfa::number($revenue['charges']) }}<span class="m-stat__unit"> FCFA</span></div>
                </a>
                <a href="{{ route('m.charges') }}" wire:navigate class="m-stat" data-haptic>
                    <div class="m-stat__label"><x-m.icon name="scale" class="m-ico-sm" /><span>Revenu net</span></div>
                    <div class="m-stat__value m-num" style="color:var(--{{ $revenue['net'] >= 0 ? 'ok' : 'danger' }})">{{ $revenue['net'] < 0 ? '−' : '' }}{{ Fcfa::number(abs($revenue['net'])) }}<span class="m-stat__unit"> FCFA</span></div>
                </a>
            </div>
            <p style="margin:8px 20px 0;font-size:12.5px;color:var(--fg-3)">{{ $revenue['label'] }}</p>
        </x-m.section>
    @endif

    @if ($showStats)
        <x-m.section title="Performance mensuelle">
            <div class="m-grid" style="margin-top:0">
                @foreach ($performance as $item)
                    @php
                        $pct = MonthOverview::pct($item['now'], $item['before']);
                        $trend = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'stable');
                    @endphp
                    <div class="m-stat" wire:key="perf-{{ $item['key'] }}">
                        <div class="m-stat__label"><x-m.icon :name="$item['icon']" class="m-ico-sm" /><span>{{ $item['label'] }}</span></div>
                        <div class="m-stat__value m-num">{{ Fcfa::number($item['now']) }}@if ($item['money'])<span class="m-stat__unit"> FCFA</span>@endif</div>
                        <x-m.delta :trend="$trend" :pct="$pct" :good="$item['good']" suffix="" />
                        <div class="m-stat__sub" style="margin-top:4px">{{ $item['vs'] }}</div>
                    </div>
                @endforeach
            </div>
        </x-m.section>

        <x-m.section title="Évolution du CA · mois en cours">
            <x-m.daily-chart :days="$daily" label="Chiffre d'affaires du mois, jour par jour" />
        </x-m.section>
    @endif
</div>
