<div wire:poll.30s.visible>
    <x-m.appbar :title="$greeting.', '.$firstName" :sub="collect([now()->translatedFormat('l j F'), auth()->user()->tenant?->name])->filter()->implode(' · ')">
        @if (\Illuminate\Support\Facades\Route::has('m.notifications'))
            <a href="{{ route('m.notifications') }}" wire:navigate class="m-iconbtn" aria-label="Notifications" data-haptic>
                <x-m.icon name="bell" />
                @if ($unread > 0) <span class="m-iconbtn__dot">{{ $unread > 99 ? '99+' : $unread }}</span> @endif
            </a>
        @endif
    </x-m.appbar>

    <div class="m-hero">
        <div class="m-hero__label">Solde UV disponible</div>
        <div class="m-hero__amount m-num">
            {{ \App\Support\Mobile\Fcfa::number($solde['solde']) }}
            <span class="m-hero__unit">FCFA</span>
        </div>
        <div class="m-hero__foot">
            <x-m.delta :trend="$solde['trend']" :pct="$solde['pct']" suffix="" />
            <span>
                {{ $solde['delta'] >= 0 ? '+' : '−' }}{{ \App\Support\Mobile\Fcfa::number(abs($solde['delta'])) }} depuis hier soir
            </span>
        </div>
    </div>

    <div class="m-strip" data-no-ptr>
        @foreach ($kpis as $kpi)
            <div class="m-stat">
                <div class="m-stat__label">
                    <x-m.icon :name="$kpi['icon']" class="m-ico-sm" />
                    <span>{{ $kpi['label'] }}</span>
                </div>
                <div class="m-stat__value m-num">
                    {{ \App\Support\Mobile\Fcfa::number($kpi['value']) }}<span class="m-stat__unit"> FCFA</span>
                </div>
                <x-m.delta :trend="$kpi['trend']" :pct="$kpi['pct']" :good="$kpi['good']" suffix="" />
            </div>
        @endforeach
    </div>

    <x-m.section title="Ventes · 7 derniers jours">
        <livewire:mobile.home-chart lazy />
    </x-m.section>

    <livewire:mobile.home-month lazy />

    <livewire:mobile.home-alerts lazy />

    <livewire:mobile.home-activity lazy />

    <livewire:mobile.home-top-agents lazy />

    @if (\App\Support\Mobile\Nav::quickActions(auth()->user()))
        <x-m.fab sheet="m-quick" label="Nouveau" />
    @endif
</div>
