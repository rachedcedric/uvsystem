@php
    $data = $this->getActivities();
    $items = $data['items'];

    $typeMeta = [
        'vente' => ['label' => 'Vente', 'color' => 'info', 'icon' => 'heroicon-o-shopping-cart'],
        'approvisionnement' => ['label' => 'Approvisionnement', 'color' => 'warning', 'icon' => 'heroicon-o-truck'],
        'remboursement' => ['label' => 'Remboursement', 'color' => 'success', 'icon' => 'heroicon-o-arrow-uturn-left'],
    ];

    $statusMeta = [
        'completed' => ['label' => 'Confirmé', 'color' => 'success'],
        'pending' => ['label' => 'En attente', 'color' => 'warning'],
        'cancelled' => ['label' => 'Annulé', 'color' => 'danger'],
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        heading="⚡ Activité en temps réel"
        description="Dernières ventes, approvisionnements et remboursements enregistrés"
    >
        <div class="overflow-x-auto">
            <table class="fi-ta-table w-full text-start">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Type</th>
                        <th class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Référence</th>
                        <th class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Tenant / Utilisateur</th>
                        <th class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-end">Montant</th>
                        <th class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Date et heure</th>
                        <th class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Statut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($items as $item)
                        @php
                            $type = $typeMeta[$item['type']] ?? ['label' => $item['type'], 'color' => 'gray', 'icon' => 'heroicon-o-document'];
                            $status = $statusMeta[$item['status']] ?? ['label' => $item['status'], 'color' => 'gray'];
                            $date = \Illuminate\Support\Carbon::parse($item['date_operation']);
                        @endphp
                        <tr wire:key="activity-{{ $item['type'] }}-{{ $item['reference'] }}">
                            <td class="px-2.5 py-1.5">
                                <x-filament::badge :color="$type['color']" :icon="$type['icon']" size="sm">
                                    {{ $type['label'] }}
                                </x-filament::badge>
                            </td>
                            <td class="px-2.5 py-1.5 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $item['reference'] }}</td>
                            <td class="px-2.5 py-1.5 text-sm text-gray-700 dark:text-gray-300">{{ $item['tiers'] }}</td>
                            <td class="px-2.5 py-1.5 text-end text-sm font-semibold text-gray-950 dark:text-white">
                                {{ number_format($item['amount'], 0, ',', ' ') }} FCFA
                            </td>
                            <td class="px-2.5 py-1.5 text-sm text-gray-700 dark:text-gray-300">
                                {{ $date->translatedFormat('d/m/Y') }}
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $date->format('H:i') }}</span>
                            </td>
                            <td class="px-2.5 py-1.5">
                                <x-filament::badge :color="$status['color']" size="sm">
                                    {{ $status['label'] }}
                                </x-filament::badge>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-2.5 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                Aucune activité enregistrée pour le moment.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-col gap-3 border-t border-gray-100 pt-4 dark:border-white/5 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if ($data['total'] > 0)
                    Page {{ $data['page'] }} / {{ $data['totalPages'] }} &middot; {{ number_format($data['total'], 0, ',', ' ') }} transaction(s) au total
                @else
                    Aucune transaction
                @endif
            </p>

            <div class="flex items-center gap-2">
                <x-filament::button
                    size="sm"
                    color="gray"
                    icon="heroicon-m-chevron-left"
                    wire:click="previousPage"
                    :disabled="$data['page'] <= 1"
                >
                    Précédent
                </x-filament::button>

                <x-filament::button
                    size="sm"
                    color="gray"
                    icon="heroicon-m-chevron-right"
                    icon-position="after"
                    wire:click="nextPage"
                    :disabled="$data['page'] >= $data['totalPages']"
                >
                    Suivant
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
