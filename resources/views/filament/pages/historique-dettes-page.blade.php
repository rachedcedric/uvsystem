@php
    $data = $this->historyData();
    $fcfa = fn ($n) => number_format((float) $n, 0, ',', ' ').' FCFA';
    $color = fn (string $tone) => match ($tone) { 'danger' => 'color: rgb(var(--danger-600))', 'ok' => 'color: rgb(var(--success-600))', default => '' };
    $range = $data['range'];
    $days = $data['days'];
@endphp
<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section compact>
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
            @else
                <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:space-between" class="mt-4">
                    <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
                        <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass" style="min-width:240px">
                            <x-filament::input type="search" wire:model.live.debounce.400ms="q" placeholder="Rechercher un nom" aria-label="Rechercher un agent ou un fournisseur" />
                        </x-filament::input.wrapper>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="nature" aria-label="Dette ou crédit">
                                @foreach ($data['natures'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="type" aria-label="Agent ou fournisseur">
                                @foreach ($data['types'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="jours" aria-label="Journées">
                                @foreach ($data['jourOptions'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        @if ($data['active'])
                            <x-filament::link tag="button" color="gray" size="sm" wire:click="resetFilters">Réinitialiser</x-filament::link>
                        @endif
                    </div>
                    @if ($data['canExport'])
                        <span style="display:flex;gap:.5rem">
                            <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-o-document-arrow-down" :href="route('exports.categorie', ['historique_dettes', 'pdf'] + $data['exportQuery'])" target="_blank">PDF</x-filament::button>
                            <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-o-table-cells" :href="route('exports.categorie', ['historique_dettes', 'xlsx'] + $data['exportQuery'])" target="_blank">Excel</x-filament::button>
                        </span>
                    @endif
                </div>
            @endif
        </x-filament::section>

        @if ($days)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $range->description() }} · {{ $days->total() }} journée{{ $days->total() > 1 ? 's' : '' }}.
                Soldes constatés à la clôture de chaque journée (23h59), jamais recalculés.
                @if ($range->to->isToday()) La journée en cours sera clôturée à minuit. @endif
            </p>

            @if ($days->isEmpty())
                <div class="rounded-xl bg-white p-6 text-center shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $data['active'] ? 'Aucune journée pour ces filtres' : 'Aucune journée clôturée sur cette période' }}</p>
                    @if ($data['active'])
                        <x-filament::link tag="button" size="sm" wire:click="resetFilters" class="mt-2">Réinitialiser les filtres</x-filament::link>
                    @endif
                </div>
            @else
                <div class="divide-y divide-gray-200 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10" wire:loading.class="opacity-50">
                    @foreach ($days as $day)
                        @php($hasLines = $day['lignes']->isNotEmpty())
                        <section wire:key="jour-{{ $day['date']->toDateString() }}" x-data="{ open: @js($loop->first && $hasLines) }" aria-label="Journée du {{ $day['date']->format('d/m/Y') }}">
                            <button type="button" @if ($hasLines) x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" @else disabled aria-disabled="true" @endif
                                    class="flex w-full items-center gap-3 px-6 py-4 text-start {{ $hasLines ? 'hover:bg-gray-50 dark:hover:bg-white/5' : 'cursor-default' }}">
                                <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $day['date']->format('d/m/Y') }}</span>
                                <span class="hidden text-sm text-gray-500 dark:text-gray-400 sm:inline">{{ ucfirst($day['date']->translatedFormat('l')) }}</span>
                                <span style="margin-inline-start:auto;display:flex;flex-wrap:wrap;gap:.375rem;justify-content:flex-end">
                                    @if ($day['ecart'])
                                        <x-filament::badge color="warning" icon="heroicon-m-exclamation-triangle" size="sm" title="Une opération datée de ce jour a été enregistrée, modifiée ou annulée après la clôture. Les soldes affichés restent ceux constatés à la clôture.">Écart signalé</x-filament::badge>
                                    @endif
                                    @if ($day['nb_dettes'] > 0)<x-filament::badge color="danger" size="sm">{{ $day['nb_dettes'] }} dette{{ $day['nb_dettes'] > 1 ? 's' : '' }}</x-filament::badge>@endif
                                    @if ($day['nb_credits'] > 0)<x-filament::badge color="success" size="sm">{{ $day['nb_credits'] }} crédit{{ $day['nb_credits'] > 1 ? 's' : '' }}</x-filament::badge>@endif
                                    @if (! $hasLines)<x-filament::badge color="gray" size="sm">{{ $day['resume'] }}</x-filament::badge>@endif
                                </span>
                                @if ($hasLines)
                                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-5 w-5 text-gray-400 transition" x-bind:class="{ 'rotate-180': open }" />
                                @else
                                    <span class="h-5 w-5" aria-hidden="true"></span>
                                @endif
                            </button>

                            @if ($hasLines)
                                <div x-show="open" x-cloak class="border-t border-gray-100 px-6 pb-4 dark:border-white/5">
                                    <ul class="divide-y divide-gray-100 dark:divide-white/5">
                                        @foreach ($day['lignes'] as $line)
                                            <li style="display:flex;align-items:center;gap:1rem" class="py-3">
                                                <span style="min-width:0;flex:1">
                                                    <a href="{{ \App\Filament\Pages\HistoriqueDettesPage::detailUrl($line) }}" class="text-sm font-medium text-gray-950 hover:underline dark:text-white">{{ $line['nom'] }}</a>
                                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $line['type_label'] }}</span>
                                                </span>
                                                <x-filament::badge :color="$line['nature'] === 'dette' ? 'danger' : 'success'" size="sm">{{ $line['nature_label'] }}</x-filament::badge>
                                                <span class="text-sm font-semibold" style="min-width:9rem;text-align:end;font-variant-numeric:tabular-nums;{{ $color($line['tone']) }}">{{ $fcfa($line['montant']) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                        Clôture enregistrée le {{ $day['closed_at']->format('d/m/Y à H:i') }}{{ $day['source'] === 'rattrapage' ? ' (rattrapage depuis les mouvements enregistrés)' : '' }}.
                                        @if ($day['ecart']) Une opération datée de ce jour a été enregistrée, modifiée ou annulée après la clôture : les soldes ci-dessus restent ceux constatés à la clôture. @endif
                                    </p>
                                </div>
                            @endif
                        </section>
                    @endforeach
                </div>

                <x-filament::pagination :paginator="$days" />
            @endif
        @endif
    </div>
</x-filament-panels::page>
