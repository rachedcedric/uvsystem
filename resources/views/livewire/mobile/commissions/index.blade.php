<div wire:poll.120s.visible>
    <x-m.appbar title="Commissions" :back="route('m.more')">
        <button type="button" class="m-iconbtn" data-sheet-open="bareme-sheet" aria-label="Barème" data-haptic><x-m.icon name="chart-bar" /></button>
        @if ($canCompute)
            <button type="button" class="m-iconbtn" data-sheet-open="calc-sheet" aria-label="Calculer" data-haptic><x-m.icon name="calculator" /></button>
        @endif
    </x-m.appbar>

    <div class="m-hero" style="margin-top:6px">
        <div class="m-hero__label">Commission prévisionnelle du jour</div>
        <div class="m-hero__amount m-num">{{ \App\Support\Mobile\Fcfa::number($forecast) }}<span class="m-hero__unit">FCFA</span></div>
        <div class="m-hero__foot" style="gap:14px;flex-wrap:wrap">
            <span>Volume {{ \App\Support\Mobile\Fcfa::format($volume) }}</span>
            <span>Hier {{ \App\Support\Mobile\Fcfa::format($yesterday) }}</span>
            <span>Clôture {{ $nextCutoff->translatedFormat('j M, H:i') }}</span>
        </div>
    </div>

    <div class="m-strip" data-no-ptr>
        <div class="m-stat"><div class="m-stat__label"><x-m.icon name="calendar-days" class="m-ico-sm" /><span>Cette semaine</span></div><div class="m-stat__value m-num">{{ \App\Support\Mobile\Fcfa::number($week) }}<span class="m-stat__unit"> FCFA</span></div></div>
        <div class="m-stat"><div class="m-stat__label"><x-m.icon name="calendar-days" class="m-ico-sm" /><span>Ce mois</span></div><div class="m-stat__value m-num">{{ \App\Support\Mobile\Fcfa::number($month) }}<span class="m-stat__unit"> FCFA</span></div></div>
        <div class="m-stat"><div class="m-stat__label"><x-m.icon name="archive-box" class="m-ico-sm" /><span>Total cumulé</span></div><div class="m-stat__value m-num">{{ \App\Support\Mobile\Fcfa::number($cumul) }}<span class="m-stat__unit"> FCFA</span></div></div>
    </div>

    <x-m.section title="Évolution des commissions · mois en cours">
        <x-m.daily-chart :days="$dailyCommissions" label="Commissions du mois, jour par jour" />
    </x-m.section>

    <x-m.section title="Évolution du CA · mois en cours">
        <x-m.daily-chart :days="$dailySales" label="Chiffre d'affaires du mois, jour par jour" />
    </x-m.section>

    <x-m.chips model="period" :current="$period" :options="['week' => 'Cette semaine', 'month' => 'Ce mois', 'all' => 'Tout']" />

    <div class="m-summary">
        <span>{{ $count }} {{ $count > 1 ? 'journées' : 'journée' }}</span>
        <strong>{{ \App\Support\Mobile\Fcfa::format($total) }}</strong>
    </div>

    <div class="m-group" style="margin-top:12px">
        @forelse ($items as $c)
            <x-m.row :href="route('m.commissions.show', $c)" wire:key="co{{ $c->id }}" icon="currency-dollar" tone="ok"
                     :title="ucfirst($c->date_commission->translatedFormat('l j F'))"
                     :sub="'Volume '.\App\Support\Mobile\Fcfa::format($c->volume_total_jour).' · '.$c->nombre_transactions.' ventes'">
                <x-slot:end><span class="m-row__amount m-amount--in">{{ \App\Support\Mobile\Fcfa::number($c->commission_globale) }}</span></x-slot:end>
            </x-m.row>
        @empty
            <x-m.empty icon="currency-dollar" title="Aucune commission" text="Les commissions sont calculées chaque jour à la clôture." />
        @endforelse
    </div>

    @if ($hasMore)
        <div wire:intersect="loadMore"></div>
        <button type="button" class="m-more" wire:click="loadMore">Voir plus</button>
    @endif

    @if ($canExport)
        <div class="m-actions">
            <a href="{{ route('m.exports.commissions', ['pdf', 'p' => $period]) }}" target="_blank" class="m-action" data-haptic><x-m.icon name="document-arrow-down" /> PDF</a>
            <a href="{{ route('m.exports.commissions', ['xlsx', 'p' => $period]) }}" target="_blank" class="m-action" data-haptic><x-m.icon name="table-cells" /> Excel</a>
        </div>
    @endif

    <div id="bareme-sheet" class="m-sheet" role="dialog" aria-label="Barème des commissions">
        <div class="m-sheet__grip"></div>
        <div class="m-sheet__title">Barème des commissions</div>
        <p style="margin:0 20px 10px;color:var(--fg-2);font-size:14px">Commission fixe selon le total des ventes de la journée (clôture à 18h).</p>
        @forelse ($paliers as $p)
            <div class="m-row" style="box-shadow:none"><span class="m-row__body"><span class="m-row__title" style="display:block">{{ \App\Support\Mobile\Fcfa::number($p->seuil_min) }} — {{ \App\Support\Mobile\Fcfa::number($p->seuil_max) }}</span></span>
                <span class="m-row__amount m-amount--in">{{ \App\Support\Mobile\Fcfa::number($p->commission) }}</span></div>
        @empty
            <x-m.empty icon="chart-bar" title="Aucun palier actif" />
        @endforelse
    </div>

    @if ($canCompute)
        <div id="calc-sheet" class="m-sheet" role="dialog" aria-label="Calculer les commissions">
            <div class="m-sheet__grip"></div>
            <div class="m-sheet__title">Calculer les commissions</div>
            <div style="padding:0 16px;display:grid;gap:12px">
                <button type="button" class="m-btn" wire:click="calculate" @click="MobileUI.closeSheets()">Calculer la commission du jour</button>

                <div class="m-field" style="margin:6px 0 0"><label class="m-field__label">Recalculer</label>
                    <div class="m-chips" style="padding:0;flex-wrap:wrap">
                        @foreach (['date' => 'Une date', 'periode' => 'Une période', 'incomplete' => 'Journées incomplètes'] as $k => $l)
                            <button type="button" class="m-chip {{ $recalcMode === $k ? 'is-on' : '' }}" wire:click="$set('recalcMode', '{{ $k }}')">{{ $l }}</button>
                        @endforeach
                    </div>
                </div>
                @if ($recalcMode === 'date')
                    <input type="date" wire:model="recalcDate" class="m-input">
                @elseif ($recalcMode === 'periode')
                    <div class="m-pair" style="gap:10px">
                        <input type="date" wire:model="recalcStart" class="m-input"><input type="date" wire:model="recalcEnd" class="m-input">
                    </div>
                @endif
                @foreach (['recalcDate', 'recalcStart', 'recalcEnd'] as $err)
                    @error($err) <div class="m-field__error">{{ $message }}</div> @enderror
                @endforeach
                <button type="button" class="m-btn m-btn--ghost" wire:click="recalculate" @click="MobileUI.closeSheets()">Recalculer</button>
            </div>
        </div>
    @endif
</div>
