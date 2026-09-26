<x-filament-panels::page>
    <div class="space-y-6">
        @livewire(\App\Filament\Widgets\CaisseStatsWidget::class, [], 'caisse-stats-'.$statsKey)

        <x-filament::section>
            <x-slot name="heading">Historique de la caisse</x-slot>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                        <x-filament::input type="text" wire:model.live.debounce.400ms="search"
                            placeholder="Rechercher une date (ex : 25/09/2026)" aria-label="Rechercher une date" />
                    </x-filament::input.wrapper>
                </div>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="dateFrom" aria-label="Du" />
                </x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="dateTo" aria-label="Au" />
                </x-filament::input.wrapper>
            </div>

            @if ($search !== '' || $dateFrom || $dateTo)
                <div class="mt-2">
                    <x-filament::link tag="button" wire:click="resetFilters" size="sm">Réinitialiser les filtres</x-filament::link>
                </div>
            @endif

            @php($paginator = $this->getPaginatedDays())

            <div class="mt-4 overflow-x-auto" wire:loading.class="opacity-60">
                <table class="w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
                    <thead>
                        <tr class="text-sm font-semibold text-gray-950 dark:text-white">
                            <th class="px-3 py-3 text-start">Date</th>
                            <th class="px-3 py-3 text-start">Nombre de mouvements</th>
                            <th class="px-3 py-3 text-end">Entrées</th>
                            <th class="px-3 py-3 text-end">Sorties</th>
                            <th class="px-3 py-3 text-end">Solde caisse</th>
                            <th class="px-3 py-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-sm text-gray-950 dark:divide-white/5 dark:text-white">
                        @forelse ($paginator as $day)
                            <tr wire:key="day-{{ $day['date']->toDateString() }}">
                                <td class="px-3 py-3">{{ $day['date']->format('d/m/Y') }}</td>
                                <td class="px-3 py-3">{{ $day['count'] }} {{ $day['count'] > 1 ? 'mouvements' : 'mouvement' }}</td>
                                <td class="px-3 py-3 text-end font-medium" style="color: rgb(var(--success-600));">+{{ \App\Services\CaisseService::fcfa($day['entrees']) }}</td>
                                <td class="px-3 py-3 text-end font-medium" style="color: rgb(var(--danger-600));">−{{ \App\Services\CaisseService::fcfa($day['sorties']) }}</td>
                                <td class="px-3 py-3 text-end font-semibold">{{ \App\Services\CaisseService::fcfa($day['solde']) }}</td>
                                <td class="px-3 py-3 text-end">
                                    <x-filament::button tag="a" :href="$this->getDetailUrl($day['date'])" size="xs" outlined>Voir les détails</x-filament::button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-3 py-10 text-center text-gray-500 dark:text-gray-400">
                                    Aucun mouvement de caisse{{ ($search !== '' || $dateFrom || $dateTo) ? ' pour ces filtres' : '' }}.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                <x-filament::pagination :paginator="$paginator" />
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
