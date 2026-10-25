@php
    $history = $this->getHistory();
    $money = fn ($n) => number_format((float) $n, 0, ',', ' ').' FCFA';
    // Rouge : dette ; vert : découvert (avance) ; neutre : équilibré. Variables de couleur du panneau (clair et sombre).
    $tone = fn ($solde) => match (\App\Services\FournisseurDebtService::statutPour((float) $solde)) {
        'dette' => 'color: rgb(var(--danger-600))',
        'decouvert' => 'color: rgb(var(--success-600))',
        default => '',
    };
    $badge = fn ($statut) => match ($statut) {
        'Dette' => 'danger', 'Découvert' => 'success', 'Payé' => 'info', default => 'gray',
    };
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        @livewire(\App\Filament\Widgets\FournisseurSituationWidget::class, ['fournisseur' => $this->record])

        <x-filament::section heading="Historique des mouvements" description="Du plus récent au plus ancien. Le solde après chaque opération est recalculé depuis les mouvements enregistrés." compact>
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            @foreach (['Date', 'Opération', 'Référence', 'Auteur', 'Montant', 'Impact', 'Solde après', 'Statut'] as $i => $h)
                                <th class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 {{ $i >= 4 && $i <= 6 ? 'text-end' : 'text-start' }}">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($history->take($this->limit) as $row)
                            <tr wire:key="fh-{{ $row['type'] }}-{{ $row['reference'] }}" @if ($row['annule']) style="opacity:.6" @endif>
                                <td class="px-2.5 py-1.5 text-sm text-gray-700 dark:text-gray-300">{{ $row['date']->format('d/m/Y') }}<span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row['date']->format('H:i') }}</span></td>
                                <td class="px-2.5 py-1.5 text-sm text-gray-950 dark:text-white">{{ $row['libelle'] }}@if ($row['note'])<span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row['note'] }}</span>@endif</td>
                                <td class="px-2.5 py-1.5 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $row['reference'] }}</td>
                                <td class="px-2.5 py-1.5 text-sm text-gray-700 dark:text-gray-300">{{ $row['auteur'] ?? '—' }}</td>
                                <td class="px-2.5 py-1.5 text-end text-sm text-gray-700 dark:text-gray-300" @if ($row['annule']) style="text-decoration:line-through" @endif>{{ $money($row['montant']) }}@if ($row['annule'])<span class="sr-only"> (annulé)</span>@endif</td>
                                <td class="px-2.5 py-1.5 text-end text-sm" style="{{ $tone($row['impact']) }}">{{ $row['impact'] > 0 ? '+' : '' }}{{ $money($row['impact']) }}</td>
                                <td class="px-2.5 py-1.5 text-end text-sm font-semibold" style="{{ $tone($row['solde_apres']) }}">{{ $money($row['solde_apres']) }}</td>
                                <td class="px-2.5 py-1.5"><x-filament::badge :color="$badge($row['statut'])" size="sm">{{ $row['statut'] }}</x-filament::badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-2.5 py-6 text-center text-sm text-gray-500 dark:text-gray-400">Aucun mouvement pour ce fournisseur.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($history->count() > $this->limit)
                <div class="mt-3 text-center"><x-filament::button color="gray" size="sm" wire:click="loadMore">Afficher plus</x-filament::button></div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
