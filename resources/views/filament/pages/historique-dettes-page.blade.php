@php
    $data = $this->historyData();
    $fcfa = fn ($n) => number_format((float) $n, 0, ',', ' ').' FCFA';
    $tone = fn ($n) => match (\App\Services\Reports\ReportPresenter::tone((float) $n)) { 'danger' => 'color: rgb(var(--danger-600))', 'ok' => 'color: rgb(var(--success-600))', default => '' };
    $range = $data['range'];
@endphp
<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Période</x-slot>
            <x-slot name="description">Le solde affiché est celui de la date de fin (23h59), recalculé depuis les mouvements enregistrés : choisissez une date passée pour consulter la situation à cette date.</x-slot>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end">
                @foreach ($data['presets'] as $key => $label)
                    <x-filament::button size="sm" :color="$periode === $key ? 'primary' : 'gray'" wire:click="$set('periode', '{{ $key }}')">{{ $label }}</x-filament::button>
                @endforeach
                @if ($periode === 'custom')
                    <label style="display:grid;gap:.25rem" class="text-sm text-gray-700 dark:text-gray-300">Date de début
                        <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="du" max="{{ now()->toDateString() }}" /></x-filament::input.wrapper>
                    </label>
                    <label style="display:grid;gap:.25rem" class="text-sm text-gray-700 dark:text-gray-300">Date de fin
                        <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="au" /></x-filament::input.wrapper>
                    </label>
                @endif
            </div>
            @if ($data['error'])
                <p role="alert" class="mt-3 text-sm" style="color: rgb(var(--danger-600))">{{ $data['error'] }}</p>
            @elseif ($range)
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ $range->description() }}</p>
            @endif
        </x-filament::section>

        @if ($range)
            @php($s = $data['summary'])
            <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(220px,1fr))" wire:loading.class="opacity-50">
                @foreach ([
                    ['Total des dettes agents', $s['dettes_agents'], 'Ce que les agents doivent au Master', 'heroicon-o-users'],
                    ['Total des dettes fournisseurs', $s['dettes_fournisseurs'], 'Ce que le Master doit à ses fournisseurs', 'heroicon-o-building-storefront'],
                    ['Total des découverts fournisseurs', $s['decouverts_fournisseurs'], 'Avances du Master (toujours signées « − »)', 'heroicon-o-banknotes'],
                    ['Solde net global', $s['solde_net'], 'Créances − dettes'.($s['credits_agents'] < 0 ? ' · crédits agents : '.$fcfa($s['credits_agents']) : ''), 'heroicon-o-scale'],
                ] as [$label, $value, $hint, $icon])
                    <div class="fi-wi-stats-overview-stat rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <span class="flex items-center gap-x-2 text-sm font-medium text-gray-500 dark:text-gray-400">@svg($icon, 'h-5 w-5') {{ $label }}</span>
                        <span class="mt-2 block text-2xl font-semibold tracking-tight text-gray-950 dark:text-white" style="{{ $label === 'Solde net global' ? '' : $tone($value) }}">{{ $fcfa($value) }}</span>
                        <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</span>
                    </div>
                @endforeach
            </div>

            <x-filament::section>
                <x-slot name="heading">Historique</x-slot>
                <x-slot name="description">{{ $data['count'] }} compte(s). Dette initiale = solde au début de la période + dettes nées pendant la période.</x-slot>

                <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:space-between;margin-bottom:.75rem">
                    <div style="display:flex;flex-wrap:wrap;gap:.5rem">
                        <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass" style="min-width:240px">
                            <x-filament::input type="search" wire:model.live.debounce.400ms="q" placeholder="Nom de l'agent ou du fournisseur" />
                        </x-filament::input.wrapper>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="type" aria-label="Type">
                                @foreach ($data['types'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="statut" aria-label="Statut">
                                @foreach ($data['statuts'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        @if ($type !== 'all' || $statut !== '' || $q !== '')
                            <x-filament::button color="gray" size="sm" wire:click="resetFilters">Réinitialiser</x-filament::button>
                        @endif
                    </div>
                    @if ($data['canExport'])
                        <span style="display:flex;gap:.5rem">
                            <x-filament::button tag="a" size="sm" icon="heroicon-o-document-arrow-down" :href="route('exports.categorie', ['historique_dettes', 'pdf'] + $data['exportQuery'])" target="_blank">Exporter en PDF</x-filament::button>
                            <x-filament::button tag="a" size="sm" color="success" icon="heroicon-o-table-cells" :href="route('exports.categorie', ['historique_dettes', 'xlsx'] + $data['exportQuery'])" target="_blank">Exporter en Excel</x-filament::button>
                        </span>
                    @endif
                </div>

                <div class="overflow-x-auto" wire:loading.class="opacity-50">
                    <table class="fi-ta-table w-full text-start">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                @foreach (['Date', 'Type', 'Nom', 'Dette initiale', 'Montant payé', 'Solde', 'Statut', ''] as $i => $h)
                                    <th class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 {{ in_array($i, [3, 4, 5], true) ? 'text-end' : 'text-start' }}">{{ $h }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse ($data['rows'] as $r)
                                <tr wire:key="h-{{ $r['type'] }}-{{ $r['id'] }}">
                                    <td class="px-2.5 py-1.5 text-sm text-gray-700 dark:text-gray-300">{{ $r['date']?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="px-2.5 py-1.5"><x-filament::badge size="sm" :color="$r['type'] === 'agent' ? 'info' : 'primary'">{{ $r['type_label'] }}</x-filament::badge></td>
                                    <td class="px-2.5 py-1.5 text-sm font-medium text-gray-950 dark:text-white">{{ $r['nom'] }}<span class="block text-xs text-gray-500 dark:text-gray-400">{{ $r['sens'] }}</span></td>
                                    <td class="px-2.5 py-1.5 text-end text-sm text-gray-700 dark:text-gray-300">{{ $fcfa($r['dette_initiale']) }}</td>
                                    <td class="px-2.5 py-1.5 text-end text-sm text-gray-700 dark:text-gray-300">{{ $fcfa($r['paye']) }}</td>
                                    <td class="px-2.5 py-1.5 text-end text-sm font-semibold" style="{{ $tone($r['solde']) }}">{{ $fcfa($r['solde']) }}</td>
                                    <td class="px-2.5 py-1.5"><x-filament::badge size="sm" :color="match ($r['tone']) { 'danger' => 'danger', 'warn' => 'warning', 'ok' => 'success', default => 'gray' }">{{ $r['statut'] }}</x-filament::badge></td>
                                    <td class="px-2.5 py-1.5 text-end">
                                        <x-filament::dropdown placement="bottom-end">
                                            <x-slot name="trigger"><x-filament::icon-button icon="heroicon-m-ellipsis-horizontal" color="gray" label="Actions" /></x-slot>
                                            <x-filament::dropdown.list>
                                                <x-filament::dropdown.list.item tag="a" :href="\App\Filament\Pages\HistoriqueDettesPage::detailUrl($r)" icon="heroicon-o-eye">Voir les détails</x-filament::dropdown.list.item>
                                            </x-filament::dropdown.list>
                                        </x-filament::dropdown>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-2.5 py-6 text-center text-sm text-gray-500 dark:text-gray-400">Aucun compte pour cette période et ces filtres.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($data['hasMore'])
                    <div class="mt-3 text-center"><x-filament::button color="gray" size="sm" wire:click="loadMore">Afficher plus</x-filament::button></div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
