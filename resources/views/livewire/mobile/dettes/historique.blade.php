@php
    $fcfa = fn ($n) => \App\Support\Mobile\Fcfa::number($n);
    $color = fn ($n) => match (\App\Services\Reports\ReportPresenter::tone((float) $n)) { 'danger' => 'var(--danger)', 'ok' => 'var(--ok)', default => 'var(--fg)' };
@endphp
<div>
    <x-m.appbar title="Historique des dettes" :sub="$range?->description()" :back="route('m.dettes')" />

    <x-m.chips model="periode" :current="$periode" :options="$presets" />
    @if ($periode === 'custom')
        <div class="m-pair" style="margin-top:8px">
            <x-m.field label="Date de début" for="hist-du"><input id="hist-du" type="date" wire:model.live="du" class="m-input" max="{{ now()->toDateString() }}"></x-m.field>
            <x-m.field label="Date de fin" for="hist-au"><input id="hist-au" type="date" wire:model.live="au" class="m-input"></x-m.field>
        </div>
    @endif

    @if ($error)
        <x-m.callout tone="danger" icon="exclamation-triangle">{{ $error }}</x-m.callout>
    @else
        <div class="m-grid" style="margin-top:12px" wire:loading.class="m-dim">
            <div class="m-stat">
                <div class="m-stat__label"><x-m.icon name="users" class="m-ico-sm" /><span>Dettes agents</span></div>
                <div class="m-stat__value m-num" style="color:{{ $color($summary['dettes_agents']) }}">{{ $fcfa($summary['dettes_agents']) }}<span class="m-stat__unit"> FCFA</span></div>
            </div>
            <div class="m-stat">
                <div class="m-stat__label"><x-m.icon name="building-storefront" class="m-ico-sm" /><span>Dettes fournisseurs</span></div>
                <div class="m-stat__value m-num" style="color:{{ $color($summary['dettes_fournisseurs']) }}">{{ $fcfa($summary['dettes_fournisseurs']) }}<span class="m-stat__unit"> FCFA</span></div>
            </div>
            <div class="m-stat">
                <div class="m-stat__label"><x-m.icon name="banknotes" class="m-ico-sm" /><span>Découverts fournisseurs</span></div>
                <div class="m-stat__value m-num" style="color:{{ $color($summary['decouverts_fournisseurs']) }}">{{ $fcfa($summary['decouverts_fournisseurs']) }}<span class="m-stat__unit"> FCFA</span></div>
            </div>
            <div class="m-stat">
                <div class="m-stat__label"><x-m.icon name="scale" class="m-ico-sm" /><span>Solde net global</span></div>
                <div class="m-stat__value m-num">{{ $fcfa($summary['solde_net']) }}<span class="m-stat__unit"> FCFA</span></div>
                <div class="m-stat__sub">Créances − dettes</div>
            </div>
        </div>
        <p class="m-note">Soldes au {{ $range->toLabel() }} à 23h59, recalculés depuis les mouvements enregistrés.@if ($summary['credits_agents'] < 0) Crédits agents (le Master doit) : {{ \App\Support\Mobile\Fcfa::format($summary['credits_agents']) }}.@endif</p>

        <x-m.chips model="type" :current="$type" :options="$types" />
        <x-m.chips model="statut" :current="$statut" :options="$statuts" />

        @if ($canExport)
            <div class="m-actions" style="margin-top:10px">
                <a href="{{ route('m.exports.categorie', ['historique_dettes', 'pdf'] + $exportQuery) }}" target="_blank" rel="noopener" class="m-action" aria-label="Exporter l'historique des dettes en PDF" data-download data-haptic><x-m.icon name="document-arrow-down" /> Exporter en PDF</a>
                <a href="{{ route('m.exports.categorie', ['historique_dettes', 'xlsx'] + $exportQuery) }}" target="_blank" rel="noopener" class="m-action" aria-label="Exporter l'historique des dettes en Excel" data-download data-haptic><x-m.icon name="table-cells" /> Exporter en Excel</a>
            </div>
        @endif

        <div style="margin-top:12px"><x-m.filter-bar search-model="q" placeholder="Nom de l'agent ou du fournisseur" :count="$count" noun="compte" nouns="comptes" :active="$active" /></div>

        <div class="m-group" wire:loading.class="m-dim">
            @forelse ($rows as $r)
                <x-m.row :href="$r['type'] === 'agent' ? route('m.dettes.agent', $r['id']) : route('m.dettes.fournisseur.show', $r['id'])" wire:key="h{{ $r['type'] }}{{ $r['id'] }}"
                         :icon="$r['type'] === 'agent' ? 'user' : 'building-storefront'" :tone="$r['tone']" :title="$r['nom']"
                         :sub="$r['type_label'].($r['date'] ? ' · '.$r['date']->format('d/m/Y') : '').' · Dette initiale '.\App\Support\Mobile\Fcfa::format($r['dette_initiale']).' · Payé '.\App\Support\Mobile\Fcfa::format($r['paye'])">
                    <x-slot:end>
                        <span class="m-row__amount" style="display:block;color:{{ $color($r['solde']) }}">{{ $fcfa($r['solde']) }}</span>
                        <x-m.status-badge :tone="$r['tone']" :label="$r['statut']" />
                    </x-slot:end>
                </x-m.row>
            @empty
                @if ($active)
                    <x-m.empty icon="magnifying-glass" title="Aucun compte pour ces filtres"><div class="m-empty__actions"><button type="button" class="m-btn m-btn--ghost" wire:click="resetFilters">Réinitialiser les filtres</button></div></x-m.empty>
                @else
                    <x-m.empty icon="check-circle" title="Aucune dette sur cette période" text="Aucun mouvement ni solde en cours." />
                @endif
            @endforelse
        </div>

        @if ($hasMore)
            <div wire:intersect="loadMore"></div>
            <button type="button" class="m-more" wire:click="loadMore">Voir plus</button>
        @endif
    @endif
</div>
