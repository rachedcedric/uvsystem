@php
    $history = $page->getAgentDailyHistory();

    $statusMeta = [
        'completed' => ['label' => 'Validé', 'color' => 'success'],
        'pending' => ['label' => 'En attente', 'color' => 'warning'],
        'cancelled' => ['label' => 'Annulé', 'color' => 'danger'],
    ];

    $typeMeta = [
        'remboursement' => ['label' => 'Remboursement', 'color' => 'success', 'icon' => 'heroicon-o-arrow-uturn-left'],
        'dette' => ['label' => 'Dette', 'color' => 'warning', 'icon' => 'heroicon-o-exclamation-triangle'],
    ];

    $detteStatusMeta = [
        'soldee' => ['label' => 'Dette soldée', 'color' => 'success'],
        'en_cours' => ['label' => 'Dette en cours', 'color' => 'warning'],
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
        heading="📋 Historique du jour — Remboursements &amp; Dettes"
        description="Opérations enregistrées aujourd'hui pour l'agent sélectionné"
        compact
    >
        @if (! $history['agentId'])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Sélectionnez un agent pour afficher son historique du jour.
            </p>
        @elseif (! $history['authorized'])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Vous n'avez pas les droits nécessaires pour consulter cet historique.
            </p>
        @elseif ($history['error'])
            <div class="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-2 text-sm text-danger-600 dark:text-danger-400">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" />
                    Impossible de charger l'historique des remboursements et dettes. Veuillez réessayer.
                </div>
                <x-filament::button size="sm" color="gray" icon="heroicon-m-arrow-path" wire:click="retryHistoryLoad">
                    Réessayer
                </x-filament::button>
            </div>
        @else
            {{-- Résumé de l'agent --}}
            <div class="mb-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Remboursements aujourd'hui</p>
                    <p class="text-base font-semibold text-success-600 dark:text-success-400">{{ number_format($history['summary']['totalRefunds'], 0, ',', ' ') }} FCFA</p>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Dettes créées aujourd'hui</p>
                    <p class="text-base font-semibold text-warning-600 dark:text-warning-400">{{ number_format($history['summary']['totalDebtsCreated'], 0, ',', ' ') }} FCFA</p>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Dette restante actuelle</p>
                    <p @class([
                        'text-base font-semibold',
                        'text-danger-600 dark:text-danger-400' => $history['summary']['remainingDebt'] > 0,
                        'text-success-600 dark:text-success-400' => $history['summary']['remainingDebt'] <= 0,
                    ])>
                        {{ number_format($history['summary']['remainingDebt'], 0, ',', ' ') }} FCFA
                    </p>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Opérations aujourd'hui</p>
                    <p class="text-base font-semibold text-gray-950 dark:text-white">{{ $history['summary']['transactionsCount'] }}</p>
                </div>
            </div>

            @if ($history['items']->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Aucune opération de remboursement ou de dette enregistrée aujourd'hui pour cet agent.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full text-start">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Heure</th>
                                <th class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Type</th>
                                <th class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Référence</th>
                                <th class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Agent</th>
                                <th class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-end">Montant</th>
                                <th class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-end">Reste dû</th>
                                <th class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($history['items'] as $entry)
                                @php
                                    $model = $entry['model'];
                                    $type = $typeMeta[$entry['type']];
                                    $status = $statusMeta[$model->status] ?? ['label' => $model->status, 'color' => 'gray'];
                                    $detteStatus = $detteStatusMeta[$entry['detteStatus']] ?? null;
                                @endphp
                                <tr wire:key="agent-history-{{ $entry['type'] }}-{{ $model->id }}">
                                    <td class="px-3 py-2.5 text-sm text-gray-700 dark:text-gray-300" title="{{ $entry['time']?->translatedFormat('d/m/Y H:i:s') }}">
                                        {{ $entry['time']?->format('H:i') }}
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <div class="flex flex-col gap-1">
                                            <x-filament::badge :color="$type['color']" :icon="$type['icon']">
                                                {{ $type['label'] }}
                                            </x-filament::badge>
                                            @if ($entry['type'] === 'dette' && $detteStatus)
                                                <x-filament::badge :color="$detteStatus['color']" size="sm">
                                                    {{ $detteStatus['label'] }}
                                                </x-filament::badge>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-3 py-2.5 font-mono text-sm text-gray-700 dark:text-gray-300">
                                        {{ $model->reference }}
                                        @if ($model->notes)
                                            <p class="mt-0.5 max-w-[16rem] truncate text-xs text-gray-400" title="{{ $model->notes }}">{{ $model->notes }}</p>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 text-sm text-gray-700 dark:text-gray-300">{{ $model->agent?->name ?? '—' }}</td>
                                    <td @class([
                                        'px-3 py-2.5 text-end text-sm font-semibold',
                                        'text-success-600 dark:text-success-400' => $entry['type'] === 'remboursement',
                                        'text-warning-600 dark:text-warning-400' => $entry['type'] === 'dette',
                                    ])>
                                        {{ $entry['type'] === 'remboursement' ? '-' : '+' }}{{ number_format((float) $model->amount, 0, ',', ' ') }} FCFA
                                    </td>
                                    <td class="px-3 py-2.5 text-end text-sm text-gray-700 dark:text-gray-300">
                                        {{ number_format(max(0, $entry['resteDuApres']), 0, ',', ' ') }} FCFA
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
                            Page {{ $history['page'] }} / {{ $history['totalPages'] }} &middot; {{ $history['total'] }} opération(s)
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
