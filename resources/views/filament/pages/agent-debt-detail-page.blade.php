@php
    $summary = $this->getSummary();
    $data = $this->getHistory();
    $items = $data['items'];
    $typeMeta = [
        'distribution' => ['label' => 'Vente', 'color' => 'danger'],
        'retour' => ['label' => 'Retour', 'color' => 'success'],
        'remboursement' => ['label' => 'Remboursement', 'color' => 'success'],
    ];
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        {{-- 4 tuiles propres à cet agent : Dette du jour / Total dette / Remboursé / Reste à payer --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <x-filament::section compact>
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-full bg-warning-100 dark:bg-warning-900">
                        <x-heroicon-o-calendar class="w-5 h-5 text-warning-600 dark:text-warning-400" />
                    </div>
                    <div>
                        <p class="text-xl font-bold text-warning-600 dark:text-warning-400">{{ number_format($summary['detteDuJour'], 0, ',', ' ') }} FCFA</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Dette du jour</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section compact>
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-full bg-primary-100 dark:bg-primary-900">
                        <x-heroicon-o-banknotes class="w-5 h-5 text-primary-600 dark:text-primary-400" />
                    </div>
                    <div>
                        <p class="text-xl font-bold text-gray-900 dark:text-white">{{ number_format($summary['totalDette'], 0, ',', ' ') }} FCFA</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Total dette</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section compact>
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-full bg-success-100 dark:bg-success-900">
                        <x-heroicon-o-arrow-uturn-left class="w-5 h-5 text-success-600 dark:text-success-400" />
                    </div>
                    <div>
                        <p class="text-xl font-bold text-success-600 dark:text-success-400">{{ number_format($summary['rembourse'], 0, ',', ' ') }} FCFA</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Remboursé</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section compact>
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-full bg-danger-100 dark:bg-danger-900">
                        <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-danger-600 dark:text-danger-400" />
                    </div>
                    <div>
                        <p class="text-xl font-bold {{ $summary['resteAPayer'] > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">{{ number_format($summary['resteAPayer'], 0, ',', ' ') }} FCFA</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Reste à payer</p>
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- Historique détaillé, plus récent en premier --}}
        <x-filament::section heading="Historique de la dette" compact>
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Date et heure</th>
                            <th class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Type</th>
                            <th class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-end">Dette</th>
                            <th class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-end">Remboursement</th>
                            <th class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-end">Solde avant</th>
                            <th class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-end">Solde après</th>
                            <th class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 text-start">Référence</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($items as $row)
                            @php
                                $type = $typeMeta[$row->type] ?? ['label' => $row->type, 'color' => 'gray'];
                                $date = \Illuminate\Support\Carbon::parse($row->date_operation);
                            @endphp
                            <tr wire:key="dette-history-{{ $row->type }}-{{ $row->reference }}">
                                <td class="px-2.5 py-1.5 text-sm text-gray-700 dark:text-gray-300">
                                    {{ $date->translatedFormat('d/m/Y') }}
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $date->format('H:i') }}</span>
                                </td>
                                <td class="px-2.5 py-1.5">
                                    <x-filament::badge :color="$type['color']" size="sm">{{ $type['label'] }}</x-filament::badge>
                                </td>
                                <td class="px-2.5 py-1.5 text-end text-sm text-gray-700 dark:text-gray-300">
                                    {{ $row->amount !== null ? number_format($row->amount, 0, ',', ' ').' FCFA' : '—' }}
                                </td>
                                <td class="px-2.5 py-1.5 text-end text-sm text-gray-700 dark:text-gray-300">
                                    {{ $row->remboursement_amount !== null ? number_format($row->remboursement_amount, 0, ',', ' ').' FCFA' : '—' }}
                                </td>
                                <td class="px-2.5 py-1.5 text-end text-xs text-gray-500 dark:text-gray-400">
                                    {{ $row->solde_uv_avant !== null ? number_format($row->solde_uv_avant, 0, ',', ' ').' FCFA' : '—' }}
                                </td>
                                <td class="px-2.5 py-1.5 text-end text-xs text-gray-500 dark:text-gray-400">
                                    {{ $row->solde_uv_apres !== null ? number_format($row->solde_uv_apres, 0, ',', ' ').' FCFA' : '—' }}
                                </td>
                                <td class="px-2.5 py-1.5 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $row->reference }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-2.5 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Aucun mouvement enregistré pour cet agent.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex flex-col gap-3 border-t border-gray-100 pt-4 dark:border-white/5 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @if ($data['total'] > 0)
                        Page {{ $data['page'] }} / {{ $data['totalPages'] }} &middot; {{ number_format($data['total'], 0, ',', ' ') }} mouvement(s) au total
                    @else
                        Aucun mouvement
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
    </div>
</x-filament-panels::page>
