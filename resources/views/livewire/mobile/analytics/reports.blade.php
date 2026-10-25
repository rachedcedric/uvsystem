@php
    $fcfa = fn ($n) => \App\Support\Mobile\Fcfa::number($n);
    $toneClass = fn ($tone) => match ($tone) { 'danger' => 'm-tone-danger', 'ok' => 'm-tone-ok', default => '' };
    $back = $category ? route('m.rapports', array_filter(['periode' => $periode !== 'month' ? $periode : null, 'du' => $du ?: null, 'au' => $au ?: null])) : route('m.more');
@endphp
<div>
    <x-m.appbar :title="$category?->label() ?? 'Rapports et exports'" :sub="$range?->description()" :back="$back" />

    @if (! $category || $category->dated())
        <x-m.chips model="periode" :current="$periode" :options="$presets" />
        @if ($periode === 'custom')
            <div class="m-pair m-space-top-sm">
                {{-- Le message d'erreur de période s'affiche une seule fois, dans l'encadré ci-dessous (comme l'historique). --}}
                <x-m.field label="Date de début" for="report-du"><input id="report-du" type="date" wire:model.live="du" class="m-input" max="{{ now()->toDateString() }}"></x-m.field>
                <x-m.field label="Date de fin" for="report-au"><input id="report-au" type="date" wire:model.live="au" class="m-input"></x-m.field>
            </div>
        @endif
    @endif

    @if ($error)
        <x-m.callout tone="danger" icon="exclamation-triangle">{{ $error }}</x-m.callout>
    @elseif ($category)
        {{-- Une catégorie : résumé, recherche, export, opérations --}}
        <div class="m-grid m-space-top" wire:loading.class="m-dim">
            <div class="m-stat m-stat--wide">
                <div class="m-stat__label"><x-m.icon :name="$category->icon()" class="m-ico-sm" /><span>{{ $summary['total_label'] }}</span></div>
                <div class="m-stat__value m-num {{ isset($summary['footer']) ? $toneClass(\App\Services\Reports\ReportPresenter::tone($summary['total'])) : '' }}">{{ $fcfa($summary['total']) }}<span class="m-stat__unit"> FCFA</span></div>
                <div class="m-stat__sub">{{ $summary['count'] }} ligne{{ $summary['count'] > 1 ? 's' : '' }}@foreach ($summary['extra'] ?? [] as $label => $value) · {{ $label }} : {{ $value }}@endforeach</div>
            </div>
        </div>

        @if ($canExport)
            <div class="m-actions m-space-top">
                <a href="{{ route('m.exports.categorie', [$category->key(), 'pdf'] + $exportQuery) }}" target="_blank" rel="noopener" class="m-action" aria-label="Exporter {{ $category->label() }} en PDF" data-download data-haptic><x-m.icon name="document-arrow-down" /> Exporter en PDF</a>
                <a href="{{ route('m.exports.categorie', [$category->key(), 'xlsx'] + $exportQuery) }}" target="_blank" rel="noopener" class="m-action" aria-label="Exporter {{ $category->label() }} en Excel" data-download data-haptic><x-m.icon name="table-cells" /> Exporter en Excel</a>
            </div>
        @endif

        <div class="m-space-top">
            <x-m.filter-bar search-model="q" :placeholder="$category->searchPlaceholder()" :count="$summary['count']" noun="ligne" nouns="lignes" :active="$q !== ''" />
        </div>

        <div class="m-group" wire:loading.class="m-dim">
            @forelse ($rows as $i => $row)
                @php($raw = $row['raw'])
                @php($href = $raw ? ($raw['type'] === 'agent' ? route('m.dettes.agent', $raw['id']) : route('m.dettes.fournisseur.show', $raw['id'])) : null)
                <x-m.row :href="$href" wire:key="r{{ $category->key() }}{{ $i }}" :title="$row['title']" :sub="$row['sub']" :chevron="(bool) $href">
                    <x-slot:end>
                        @if ($row['amount'] !== null)
                            <span class="m-block {{ $row['signed'] ? $toneClass(\App\Services\Reports\ReportPresenter::tone($row['amount'])) : '' }}"><x-m.amount :value="$row['amount']" :cancelled="$row['cancelled']" class="m-row__amount" /></span>
                        @endif
                        @if ($row['status'])
                            <x-m.status-badge :tone="$row['cancelled'] ? 'muted' : ($raw['tone'] ?? 'muted')" :label="$row['status']" />
                        @endif
                    </x-slot:end>
                </x-m.row>
            @empty
                @if ($q !== '')
                    <x-m.empty icon="magnifying-glass" title="Aucun résultat pour cette recherche"><div class="m-empty__actions"><button type="button" class="m-btn m-btn--ghost" wire:click="resetFilters">Réinitialiser les filtres</button></div></x-m.empty>
                @else
                    <x-m.empty :icon="$category->icon()" title="Aucune opération" text="Aucune opération sur cette période." />
                @endif
            @endforelse
        </div>

        @if ($hasMore)
            <div wire:intersect="loadMore"></div>
            <button type="button" class="m-more" wire:click="loadMore">Voir plus</button>
        @endif
    @else
        {{-- Tableau de bord de la période --}}
        @if ($tiles)
            <div class="m-grid m-space-top" wire:loading.class="m-dim">
                @foreach ($tiles as $tile)
                    <a class="m-stat" href="{{ route('m.rapports', ['c' => $tile['key']] + $range->toQuery()) }}" wire:navigate wire:key="t{{ $tile['key'] }}">
                        <div class="m-stat__label"><x-m.icon :name="$tile['icon']" class="m-ico-sm" /><span>{{ $tile['label'] }}</span></div>
                        <div class="m-stat__value m-num {{ $toneClass($tile['tone']) }}">{{ $fcfa($tile['value']) }}<span class="m-stat__unit"> FCFA</span></div>
                        <div class="m-stat__sub">{{ $tile['sub'] }}</div>
                    </a>
                @endforeach
            </div>
        @endif

        <x-m.section title="Catégories de rapports">
            <div class="m-group">
                @foreach ($categories as $key => $c)
                    <x-m.row :href="route('m.rapports', ['c' => $key] + $range->toQuery())" wire:key="c{{ $key }}" :icon="$c->icon()" :title="$c->label()" :sub="$c->description()" />
                @endforeach
            </div>
        </x-m.section>

        @if ($canExport)
            <p class="m-note">Chaque catégorie s'exporte en PDF ou en Excel pour la période choisie (date de fin incluse), avec la recherche active.</p>
        @else
            <x-m.callout tone="warn">Vous n'avez pas le droit d'exporter des rapports.</x-m.callout>
        @endif

        @if (auth()->user()->hasRole('master'))
            <div class="m-actions m-actions--separated">
                <a href="{{ route('exports.tenant-data') }}" target="_blank" rel="noopener" class="m-action" aria-label="Télécharger toutes mes données au format ZIP" data-download data-haptic><x-m.icon name="document-arrow-down" /> Exporter toutes mes données (ZIP)</a>
            </div>
        @endif
    @endif
</div>
