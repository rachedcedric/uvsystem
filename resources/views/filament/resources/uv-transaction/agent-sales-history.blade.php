@php
    $history = $page->getAgentSalesHistory();

    $statusMeta = [
        'completed' => ['label' => 'Validée', 'color' => 'success'],
        'pending' => ['label' => 'En attente', 'color' => 'warning'],
        'cancelled' => ['label' => 'Annulée', 'color' => 'danger'],
    ];
@endphp

<div
    wire:loading.flex
    wire:target="data.agent_id"
    class="hidden items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-500 dark:border-white/10 dark:bg-gray-900 dark:text-gray-400"
>
    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
    </svg>
    Chargement des transactions…
</div>

<div wire:loading.remove wire:target="data.agent_id">
    <x-filament::section
        heading="📋 Historique des Ventes UV du jour"
        description="Ventes enregistrées aujourd'hui pour l'agent sélectionné"
        compact
    >
        @if (! $history['agentId'])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Sélectionnez un agent pour afficher ses ventes UV du jour.
            </p>
        @elseif (! $history['authorized'])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Vous n'avez pas les droits nécessaires pour consulter l'historique des ventes UV.
            </p>
        @elseif ($history['error'])
            <div class="flex items-center gap-2 text-sm text-danger-600 dark:text-danger-400">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" />
                Impossible de charger l'historique des Ventes UV. Veuillez réessayer.
            </div>
        @else
            {{-- Résumé journalier --}}
            <div class="mb-3 flex flex-wrap gap-2">
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Ventes aujourd'hui</p>
                    <p class="text-base font-semibold text-gray-950 dark:text-white">{{ $history['totalTransactions'] }} vente(s)</p>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Total vendu</p>
                    <p class="text-base font-semibold text-gray-950 dark:text-white">{{ number_format($history['totalAmount'], 0, ',', ' ') }} FCFA</p>
                </div>
            </div>

            @if (count($history['items']) === 0)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Aucune Vente UV enregistrée aujourd'hui pour cet agent.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full text-start">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Heure</th>
                                <th class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Référence</th>
                                <th class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Agent</th>
                                <th class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-end">Montant</th>
                                <th class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-end">Solde avant</th>
                                <th class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-end">Solde après</th>
                                <th class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($history['items'] as $item)
                                @php
                                    $status = $statusMeta[$item->status] ?? ['label' => $item->status, 'color' => 'gray'];
                                @endphp
                                <tr wire:key="agent-sale-history-{{ $item->id }}">
                                    <td class="px-3 py-2.5 text-sm text-gray-700 dark:text-gray-300" title="{{ $item->transaction_date?->translatedFormat('d/m/Y H:i:s') }}">
                                        {{ $item->transaction_date?->format('H:i') }}
                                    </td>
                                    <td class="px-3 py-2.5 font-mono text-sm text-gray-700 dark:text-gray-300">{{ $item->reference }}</td>
                                    <td class="px-3 py-2.5 text-sm text-gray-700 dark:text-gray-300">{{ $item->agent?->name ?? '—' }}</td>
                                    <td class="px-3 py-2.5 text-end text-sm font-semibold text-gray-950 dark:text-white">
                                        {{ number_format((float) $item->amount, 0, ',', ' ') }} FCFA
                                    </td>
                                    <td class="px-3 py-2.5 text-end text-sm text-gray-700 dark:text-gray-300">
                                        {{ $item->solde_uv_avant !== null ? number_format((float) $item->solde_uv_avant, 0, ',', ' ').' FCFA' : '—' }}
                                    </td>
                                    <td class="px-3 py-2.5 text-end text-sm text-gray-700 dark:text-gray-300">
                                        {{ $item->solde_uv_apres !== null ? number_format((float) $item->solde_uv_apres, 0, ',', ' ').' FCFA' : '—' }}
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <x-filament::badge :color="$status['color']">
                                            {{ $status['label'] }}
                                        </x-filament::badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($history['totalPages'] > 1)
                    <div class="mt-3 flex flex-col gap-2 border-t border-gray-100 pt-3 dark:border-white/5 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Page {{ $history['page'] }} / {{ $history['totalPages'] }} &middot; {{ $history['total'] }} transaction(s)
                        </p>

                        <div class="flex items-center gap-2">
                            <x-filament::button
                                size="sm"
                                color="gray"
                                icon="heroicon-m-chevron-left"
                                wire:click="previousHistoryPage"
                                :disabled="$history['page'] <= 1"
                            >
                                Précédent
                            </x-filament::button>

                            <x-filament::button
                                size="sm"
                                color="gray"
                                icon="heroicon-m-chevron-right"
                                icon-position="after"
                                wire:click="nextHistoryPage"
                                :disabled="$history['page'] >= $history['totalPages']"
                            >
                                Suivant
                            </x-filament::button>
                        </div>
                    </div>
                @endif
            @endif
        @endif
    </x-filament::section>
</div>
