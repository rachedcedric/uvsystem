@php($o = $overview)
<div>
    <x-m.appbar title="Statistiques" :sub="$range[0]->translatedFormat('j M').' → '.$range[1]->translatedFormat('j M Y')" :back="route('m.more')" />

    <x-m.chips model="period" :current="$period"
               :options="['day' => 'Aujourd\'hui', 'week' => 'Semaine', 'month' => 'Mois', 'quarter' => 'Trimestre', 'year' => 'Année', 'custom' => 'Dates']" />

    @if ($period === 'custom')
        <div class="m-pair" style="gap:10px;padding:10px 12px 0">
            <input type="date" wire:model.live="from" class="m-input" max="{{ today()->toDateString() }}">
            <input type="date" wire:model.live="to" class="m-input" max="{{ today()->toDateString() }}">
        </div>
    @endif

    <div style="padding:10px 12px 0">
        <x-m.agent-picker :agents="$agents" :selected="$selectedAgent" model="agentId" label="Tous les agents" id="stats-agent" />
        @if ($selectedAgent)
            <button type="button" class="m-section__link" style="margin:6px 4px 0" wire:click="clearAgent">Voir tous les agents</button>
        @endif
    </div>

    <div class="m-hero" style="margin-top:12px">
        <div class="m-hero__label">Chiffre d'affaires</div>
        <div class="m-hero__amount m-num">{{ \App\Support\Mobile\Fcfa::number($o['ca']) }}<span class="m-hero__unit">FCFA</span></div>
        <div class="m-hero__foot" style="gap:12px;flex-wrap:wrap">
            <x-m.delta :trend="$trends['ca'] > 0 ? 'up' : ($trends['ca'] < 0 ? 'down' : 'stable')" :pct="$trends['ca']" suffix="vs période précédente" />
            <span>{{ $o['ventes'] }} ventes</span>
        </div>
    </div>

    <div class="m-grid">
        <div class="m-stat"><div class="m-stat__label"><x-m.icon name="arrow-uturn-left" class="m-ico-sm" /><span>Remboursé</span></div>
            <div class="m-stat__value m-num" style="color:var(--ok)">{{ \App\Support\Mobile\Fcfa::number($o['rembourse']) }}<span class="m-stat__unit"> FCFA</span></div>
            <x-m.delta :trend="$trends['rembourse'] > 0 ? 'up' : ($trends['rembourse'] < 0 ? 'down' : 'stable')" :pct="$trends['rembourse']" suffix="" /></div>
        <div class="m-stat"><div class="m-stat__label"><x-m.icon name="shield-check" class="m-ico-sm" /><span>Recouvrement</span></div>
            <div class="m-stat__value m-num">{{ number_format($o['recouvrement'], 1, ',', '') }} %</div></div>
        <div class="m-stat"><div class="m-stat__label"><x-m.icon name="star" class="m-ico-sm" /><span>Commissions</span></div>
            <div class="m-stat__value m-num">{{ \App\Support\Mobile\Fcfa::number($o['commissions']) }}<span class="m-stat__unit"> FCFA</span></div></div>
        <div class="m-stat"><div class="m-stat__label"><x-m.icon name="truck" class="m-ico-sm" /><span>Approvisionné</span></div>
            <div class="m-stat__value m-num">{{ \App\Support\Mobile\Fcfa::number($o['approvisionnements']) }}<span class="m-stat__unit"> FCFA</span></div></div>
        <div class="m-stat"><div class="m-stat__label"><x-m.icon name="receipt-percent" class="m-ico-sm" /><span>Vente moyenne</span></div>
            <div class="m-stat__value m-num">{{ \App\Support\Mobile\Fcfa::number($o['ticket_moyen']) }}<span class="m-stat__unit"> FCFA</span></div></div>
        <div class="m-stat"><div class="m-stat__label"><x-m.icon name="users" class="m-ico-sm" /><span>Agents actifs</span></div>
            <div class="m-stat__value m-num">{{ $o['agents_actifs'] }}</div></div>
    </div>

    <x-m.section title="Ventes et paiements">
        <div class="m-group">
            <div class="m-bars" style="height:150px;overflow-x:auto;gap:{{ count($series) > 20 ? 3 : 8 }}px" data-no-ptr>
                @foreach ($series as $p)
                    <div class="m-bars__col" style="min-width:{{ count($series) > 20 ? 12 : 0 }}px">
                        <span class="m-bars__track" style="display:flex;align-items:flex-end;justify-content:center;gap:2px">
                            <span style="width:45%;max-width:16px;height:{{ max(2, round($p['ca'] / $maxSeries * 100)) }}%;border-radius:4px 4px 2px 2px;background:var(--brand)"></span>
                            <span style="width:45%;max-width:16px;height:{{ max(2, round($p['rembourse'] / $maxSeries * 100)) }}%;border-radius:4px 4px 2px 2px;background:var(--leaf);opacity:.55"></span>
                        </span>
                        @if (count($series) <= 31 || $loop->index % 5 === 0)<span class="m-bars__lbl" style="font-size:10.5px">{{ $p['label'] }}</span>@endif
                    </div>
                @endforeach
            </div>
            <div class="m-chart-foot"><span><b style="color:var(--brand)">■</b> Ventes</span><span><b style="color:var(--leaf);opacity:.6">■</b> Remboursements</span></div>
        </div>
    </x-m.section>

    <x-m.section title="Meilleurs agents">
        <div class="m-group">
            @php($maxCa = max(1, $topAgents->max('ca')))
            @forelse ($topAgents as $i => $a)
                <x-m.row :href="route('m.agents.show', $a['id'])" :lead="$i + 1" :title="$a['name']" :sub="$a['ventes'].' ventes'">
                    <x-slot:body><span class="m-meter" style="display:block"><i style="width: {{ round($a['ca'] / $maxCa * 100) }}%"></i></span></x-slot:body>
                    <x-slot:end><span class="m-row__amount">{{ \App\Support\Mobile\Fcfa::format($a['ca']) }}</span></x-slot:end>
                </x-m.row>
            @empty
                <x-m.empty icon="trophy" title="Aucune vente sur la période" />
            @endforelse
        </div>
    </x-m.section>

    @if ($modes->isNotEmpty())
        <x-m.section title="Modes de paiement">
            <div class="m-group">
                @foreach ($modes as $m)
                    <x-m.row icon="credit-card" tone="ok" :title="$m['label']" :sub="$m['count'].' paiements'" :chevron="false">
                        <x-slot:end><span class="m-row__amount">{{ \App\Support\Mobile\Fcfa::format($m['total']) }}</span></x-slot:end>
                    </x-m.row>
                @endforeach
            </div>
        </x-m.section>
    @endif

    <x-m.section title="Situation des dettes">
        <div class="m-grid" style="margin-top:0">
            <div class="m-stat" style="grid-column:span 2"><div class="m-stat__label"><x-m.icon name="exclamation-triangle" class="m-ico-sm" /><span>Dette totale des agents</span></div>
                <div class="m-stat__value m-num" style="color:var(--warn)">{{ \App\Support\Mobile\Fcfa::number($debt['total']) }}<span class="m-stat__unit"> FCFA</span></div></div>
            <div class="m-stat"><div class="m-stat__label"><span>Critiques (≥ 90 %)</span></div><div class="m-stat__value" style="color:var(--danger)">{{ $debt['critiques'] }}</div></div>
            <div class="m-stat"><div class="m-stat__label"><span>À surveiller (70–89 %)</span></div><div class="m-stat__value" style="color:var(--warn)">{{ $debt['surveiller'] }}</div></div>
            <div class="m-stat"><div class="m-stat__label"><span>Avec dette</span></div><div class="m-stat__value">{{ $debt['avec_dette'] }}</div></div>
            <div class="m-stat"><div class="m-stat__label"><span>Sans dette</span></div><div class="m-stat__value" style="color:var(--ok)">{{ $debt['sans_dette'] }}</div></div>
        </div>
        @if ($debtors->isNotEmpty())
            <div class="m-group" style="margin-top:12px">
                @foreach ($debtors as $d)
                    @php($lvl = $d['pct'] >= 100 ? 'critical' : ($d['pct'] >= 90 ? 'high' : ($d['pct'] >= 70 ? 'watch' : 'ok')))
                    <x-m.row :href="route('m.agents.show', $d['id'])" :lead="mb_strtoupper(mb_substr($d['name'], 0, 2))" tone="warn" :title="$d['name']">
                        <x-slot:body><span class="m-meter m-meter--{{ $lvl }}" style="display:block"><i style="width: {{ min(100, round($d['pct'])) }}%"></i></span></x-slot:body>
                        <x-slot:end><span class="m-row__amount" style="display:block">{{ \App\Support\Mobile\Fcfa::format($d['debt']) }}</span><span class="m-badge m-badge--warn">{{ round($d['pct']) }} %</span></x-slot:end>
                    </x-m.row>
                @endforeach
            </div>
        @endif
    </x-m.section>
</div>
