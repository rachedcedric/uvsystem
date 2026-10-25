<div>
    <x-m.appbar title="Historique des dettes et crédits" :sub="$range?->description()" :back="route('m.dettes')" />

    <x-m.chips model="periode" :current="$periode" :options="$presets" />
    @if ($periode === 'custom')
        <div class="m-pair m-space-top-sm">
            <x-m.field label="Date de début" for="hist-du"><input id="hist-du" type="date" wire:model.live="du" class="m-input" max="{{ now()->toDateString() }}"></x-m.field>
            <x-m.field label="Date de fin" for="hist-au"><input id="hist-au" type="date" wire:model.live="au" class="m-input"></x-m.field>
        </div>
    @endif

    @if ($error)
        <x-m.callout tone="danger" icon="exclamation-triangle">{{ $error }}</x-m.callout>
    @else
        <div class="m-space-top"><x-m.filter-bar search-model="q" placeholder="Rechercher un nom" :count="$days->total()" noun="journée" nouns="journées" :active="$active" /></div>
        <x-m.chips model="nature" :current="$nature" :options="$natures" />
        <x-m.chips model="type" :current="$type" :options="$types" />
        <x-m.chips model="jours" :current="$jours" :options="$jourOptions" />

        @if ($canExport)
            <div class="m-actions m-space-top">
                <a href="{{ route('m.exports.categorie', ['historique_dettes', 'pdf'] + $exportQuery) }}" target="_blank" rel="noopener" class="m-action" aria-label="Exporter l'historique des dettes et crédits en PDF" data-download data-haptic><x-m.icon name="document-arrow-down" /> PDF</a>
                <a href="{{ route('m.exports.categorie', ['historique_dettes', 'xlsx'] + $exportQuery) }}" target="_blank" rel="noopener" class="m-action" aria-label="Exporter l'historique des dettes et crédits en Excel" data-download data-haptic><x-m.icon name="table-cells" /> Excel</a>
            </div>
        @endif

        <p class="m-hday-note">Soldes constatés à la clôture de chaque journée (23h59), jamais recalculés.@if ($range->to->isToday()) La journée en cours sera clôturée à minuit.@endif</p>

        <div class="m-hdays" wire:loading.class="m-dim">
            @forelse ($days as $day)
                @if ($day['lignes']->isNotEmpty())
                    <details class="m-hday" wire:key="hj{{ $day['date']->toDateString() }}" wire:ignore.self @if ($loop->first) open @endif>
                        <summary class="m-hday__head">
                            <span class="m-hday__date">{{ $day['date']->format('d/m/Y') }}<small>{{ ucfirst($day['date']->translatedFormat('l')) }}</small></span>
                            <span class="m-hday__tags">
                                @if ($day['nb_dettes'] > 0)<span class="m-hday__tag m-hday__tag--danger">{{ $day['nb_dettes'] }} dette{{ $day['nb_dettes'] > 1 ? 's' : '' }}</span>@endif
                                @if ($day['nb_credits'] > 0)<span class="m-hday__tag m-hday__tag--ok">{{ $day['nb_credits'] }} crédit{{ $day['nb_credits'] > 1 ? 's' : '' }}</span>@endif
                                @if ($day['ecart'])<span class="m-hday__tag m-hday__tag--warn">Écart</span>@endif
                            </span>
                            <x-m.icon name="chevron-down" class="m-hday__chev" />
                        </summary>
                        <ul class="m-hday__lines">
                            @foreach ($day['lignes'] as $line)
                                <li class="m-hday__line">
                                    <a class="m-hday__name" href="{{ $line['type'] === 'agent' ? route('m.dettes.agent', $line['id']) : route('m.dettes.fournisseur.show', $line['id']) }}">{{ $line['nom'] }}<small>{{ $line['type_label'] }}</small></a>
                                    <span class="m-hday__amount m-num {{ $line['nature'] === 'dette' ? 'm-tone-danger' : 'm-tone-ok' }}"><small>{{ $line['nature_label'] }}</small>{{ \App\Support\Mobile\Fcfa::format($line['montant']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="m-hday__meta">
                            Clôture du {{ $day['closed_at']->format('d/m/Y à H:i') }}{{ $day['source'] === 'rattrapage' ? ' (rattrapage)' : '' }}.
                            @if ($day['ecart']) Une opération datée de ce jour a été saisie, modifiée ou annulée après la clôture : soldes de la clôture conservés. @endif
                        </p>
                    </details>
                @else
                    <div class="m-hday m-hday--empty" wire:key="hj{{ $day['date']->toDateString() }}">
                        <div class="m-hday__head">
                            <span class="m-hday__date">{{ $day['date']->format('d/m/Y') }}<small>{{ ucfirst($day['date']->translatedFormat('l')) }}</small></span>
                            <span class="m-hday__tags"><span class="m-hday__tag m-hday__tag--muted">{{ $day['resume'] }}</span></span>
                        </div>
                    </div>
                @endif
            @empty
                @if ($active)
                    <x-m.empty icon="magnifying-glass" title="Aucune journée pour ces filtres"><div class="m-empty__actions"><button type="button" class="m-btn m-btn--ghost" wire:click="resetFilters">Réinitialiser les filtres</button></div></x-m.empty>
                @else
                    <x-m.empty icon="clock" title="Aucune journée clôturée sur cette période" text="Chaque journée est clôturée automatiquement à minuit." />
                @endif
            @endforelse
        </div>

        @if ($hasMore)
            <div wire:intersect="loadMore"></div>
            <button type="button" class="m-more" wire:click="loadMore">Voir plus de journées</button>
        @endif
    @endif
</div>
