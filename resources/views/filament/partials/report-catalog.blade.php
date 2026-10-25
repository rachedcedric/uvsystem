{{-- Rapports et exports (Web) : même catalogue, mêmes chiffres et mêmes exports que /m/rapports. $data = $this->reportData(). --}}
@php
    $fcfa = fn ($n) => number_format((float) $n, 0, ',', ' ').' FCFA';
    $tone = fn ($t) => match ($t) { 'danger' => 'color: rgb(var(--danger-600))', 'ok' => 'color: rgb(var(--success-600))', default => '' };
    $range = $data['range'];
    $category = $data['category'];
@endphp

<x-filament::section>
    <x-slot name="heading">Période</x-slot>
    <x-slot name="description">Toutes les tuiles, listes et exports portent sur cette période (date de fin incluse, fuseau Africa/Abidjan).</x-slot>

    <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end">
        @foreach ($data['presets'] as $key => $label)
            <x-filament::button size="sm" :color="$periode === $key ? 'primary' : 'gray'" wire:click="$set('periode', '{{ $key }}')" :aria-pressed="$periode === $key ? 'true' : 'false'">{{ $label }}</x-filament::button>
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
    <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(220px,1fr))" wire:loading.class="opacity-50">
        @foreach ($data['tiles'] as $tile)
            <button type="button" wire:click="selectCategorie('{{ $tile['key'] }}')" wire:key="tile-{{ $tile['key'] }}"
                    class="fi-wi-stats-overview-stat rounded-xl bg-white p-6 text-start shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <span class="flex items-center gap-x-2 text-sm font-medium text-gray-500 dark:text-gray-400">@svg('heroicon-o-'.$tile['icon'], 'h-5 w-5') {{ $tile['label'] }}</span>
                <span class="mt-2 block text-2xl font-semibold tracking-tight text-gray-950 dark:text-white" style="{{ $tone($tile['tone']) }}">{{ $fcfa($tile['value']) }}</span>
                <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ $tile['sub'] }}</span>
            </button>
        @endforeach
    </div>

    <x-filament::section>
        <x-slot name="heading">Catégories de rapports</x-slot>
        <x-slot name="description">Consultez les opérations d'une catégorie ou exportez-la directement en PDF ou en Excel.</x-slot>
        <div class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($data['categories'] as $key => $c)
                <div wire:key="cat-{{ $key }}" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:space-between;padding:.6rem 0">
                    <button type="button" wire:click="selectCategorie('{{ $key }}')" class="flex items-center gap-3 text-start">
                        @svg('heroicon-o-'.$c->icon(), 'h-5 w-5 text-gray-400')
                        <span>
                            <span class="block text-sm font-semibold text-gray-950 dark:text-white">{{ $c->label() }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $c->description() }}</span>
                        </span>
                    </button>
                    @if ($data['canExport'])
                        <span style="display:flex;gap:.5rem">
                            <x-filament::button tag="a" size="xs" color="gray" icon="heroicon-o-document-arrow-down" :href="route('exports.categorie', [$key, 'pdf'] + $range->toQuery())" target="_blank">PDF</x-filament::button>
                            <x-filament::button tag="a" size="xs" color="gray" icon="heroicon-o-table-cells" :href="route('exports.categorie', [$key, 'xlsx'] + $range->toQuery())" target="_blank">Excel</x-filament::button>
                        </span>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>

    @if ($category)
        @php($summary = $data['summary'])
        <x-filament::section id="report-category">
            <x-slot name="heading">{{ $category->label() }}</x-slot>
            <x-slot name="description">{{ $summary['count'] }} ligne(s) · {{ $summary['total_label'] }} : {{ $fcfa($summary['total']) }}@foreach ($summary['extra'] ?? [] as $label => $value) · {{ $label }} : {{ $value }}@endforeach</x-slot>
            <x-slot name="headerEnd">
                <x-filament::button color="gray" size="sm" wire:click="selectCategorie('')">Fermer</x-filament::button>
            </x-slot>

            <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between;margin-bottom:.75rem">
                <x-filament::input.wrapper style="min-width:260px" prefix-icon="heroicon-o-magnifying-glass">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="q" :placeholder="$category->searchPlaceholder()" />
                </x-filament::input.wrapper>
                @if ($data['canExport'])
                    <span style="display:flex;gap:.5rem">
                        <x-filament::button tag="a" size="sm" icon="heroicon-o-document-arrow-down" :href="route('exports.categorie', [$category->key(), 'pdf'] + $data['exportQuery'])" target="_blank">Exporter en PDF</x-filament::button>
                        <x-filament::button tag="a" size="sm" color="success" icon="heroicon-o-table-cells" :href="route('exports.categorie', [$category->key(), 'xlsx'] + $data['exportQuery'])" target="_blank">Exporter en Excel</x-filament::button>
                    </span>
                @endif
            </div>

            <div class="overflow-x-auto" wire:loading.class="opacity-50">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            @foreach ($category->columns() as $column)
                                <th class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 {{ in_array($column['type'], ['money', 'signed'], true) ? 'text-end' : 'text-start' }}">{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($data['rows'] as $i => $row)
                            <tr wire:key="row-{{ $category->key() }}-{{ $i }}" @if (! empty($row['cancelled'])) style="opacity:.6" @endif>
                                @foreach ($row['cells'] as $j => $cell)
                                    @php($type = $category->columns()[$j]['type'])
                                    <td class="px-2.5 py-1.5 text-sm text-gray-700 dark:text-gray-300 {{ in_array($type, ['money', 'signed'], true) ? 'text-end' : '' }}"
                                        style="{{ $type === 'signed' ? $tone(\App\Services\Reports\ReportPresenter::tone((float) $cell)) : '' }}{{ ! empty($row['cancelled']) && $type === 'money' ? ';text-decoration:line-through' : '' }}">
                                        @if ($type === 'status')
                                            <x-filament::badge size="sm" :color="match ($row['tone'] ?? null) { 'danger' => 'danger', 'ok' => 'success', 'warn' => 'warning', default => (! empty($row['cancelled']) ? 'gray' : 'primary') }">{{ $cell }}</x-filament::badge>
                                        @else
                                            {{ \App\Services\Reports\ReportPresenter::text($cell, $type) }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($category->columns()) }}" class="px-2.5 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ $q !== '' ? 'Aucun résultat pour cette recherche.' : 'Aucune opération sur cette période.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($data['hasMore'])
                <div class="mt-3 text-center"><x-filament::button color="gray" size="sm" wire:click="loadMore">Afficher plus</x-filament::button></div>
            @endif
        </x-filament::section>
    @endif
@endif
