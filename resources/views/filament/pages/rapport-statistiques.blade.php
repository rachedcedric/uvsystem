<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Filtres --}}
        <x-filament::section>
            <x-slot name="heading">
                📊 Filtres de Date
            </x-slot>

            <form wire:submit="$refresh">
                {{ $this->form }}

                <div class="mt-4">
                    <x-filament::button type="submit" color="primary">
                        Actualiser les statistiques
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        {{-- Statistiques Globales --}}
        @php
            $stats = $this->getStats();
        @endphp

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            {{-- CA Total --}}
            <x-filament::section class="bg-green-50">
                <div class="text-center">
                    <div class="text-3xl font-bold text-green-600">
                        {{ number_format($stats['ca_total'], 0, ',', ' ') }} FCFA
                    </div>
                    <div class="text-sm text-gray-600 mt-2">
                        💰 Chiffre d'Affaires Total
                    </div>
                    <div class="text-xs text-gray-500 mt-1">
                        {{ $stats['nb_ventes'] }} vente(s)
                    </div>
                </div>
            </x-filament::section>

            {{-- Commissions Total --}}
            <x-filament::section class="bg-blue-50">
                <div class="text-center">
                    <div class="text-3xl font-bold text-blue-600">
                        {{ number_format($stats['commissions_total'], 0, ',', ' ') }} FCFA
                    </div>
                    <div class="text-sm text-gray-600 mt-2">
                        💸 Commissions Totales
                    </div>
                    <div class="text-xs text-gray-500 mt-1">
                        Moyenne: {{ number_format($stats['commission_moyenne'], 0, ',', ' ') }} FCFA
                    </div>
                </div>
            </x-filament::section>

            {{-- Dette Totale --}}
            <x-filament::section class="bg-red-50">
                <div class="text-center">
                    <div class="text-3xl font-bold text-red-600">
                        {{ number_format($stats['dette_totale'], 0, ',', ' ') }} FCFA
                    </div>
                    <div class="text-sm text-gray-600 mt-2">
                        ⚠️ Dette Totale Agents
                    </div>
                    <div class="text-xs text-gray-500 mt-1">
                        Remboursements: {{ number_format($stats['remboursements_total'], 0, ',', ' ') }} FCFA
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- Top 10 Agents --}}
        <x-filament::section>
            <x-slot name="heading">
                🏆 Top 10 Agents (CA Période)
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="px-4 py-2 text-left">Rang</th>
                            <th class="px-4 py-2 text-left">Agent</th>
                            <th class="px-4 py-2 text-right">CA Total</th>
                            <th class="px-4 py-2 text-center">Badge</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->getTopAgents() as $index => $agent)
                            <tr class="border-b hover:bg-gray-50">
                                <td class="px-4 py-3 font-bold">
                                    @if ($index === 0)
                                        🥇 #{{ $index + 1 }}
                                    @elseif($index === 1)
                                        🥈 #{{ $index + 1 }}
                                    @elseif($index === 2)
                                        🥉 #{{ $index + 1 }}
                                    @else
                                        #{{ $index + 1 }}
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    {{ $agent['agent']['name'] ?? ($agent['agent']['nom_entreprise'] ?? 'N/A') }}
                                </td>
                                <td class="px-4 py-3 text-right font-semibold text-green-600">
                                    {{ number_format($agent['total_ca'], 0, ',', ' ') }} FCFA
                                </td>
                                <td class="px-4 py-3 text-center">
                                    @if ($index < 3)
                                        <span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded text-xs">
                                            ⭐ TOP 3
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                                    Aucune donnée disponible pour la période sélectionnée
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- Graphique CA vs Commissions --}}
        @php
            $chartData = $this->getChartData();
        @endphp

        <x-filament::section>
            <x-slot name="heading">
                📈 Évolution CA & Commissions
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="px-3 py-2 text-left">Date</th>
                            <th class="px-3 py-2 text-right">CA</th>
                            <th class="px-3 py-2 text-right">Commissions</th>
                            <th class="px-3 py-2 text-right">% Commission</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $caByDate = collect($chartData['ca_par_jour'])->keyBy('date');
                            $commByDate = collect($chartData['commissions_par_jour'])->keyBy('date');
                            $allDates = $caByDate->keys()->merge($commByDate->keys())->unique()->sort();
                        @endphp

                        @forelse($allDates as $date)
                            @php
                                $ca = $caByDate->get($date)?->total ?? 0;
                                $comm = $commByDate->get($date)?->total ?? 0;
                                $percentage = $ca > 0 ? ($comm / $ca) * 100 : 0;
                            @endphp
                            <tr class="border-b hover:bg-gray-50">
                                <td class="px-3 py-2">{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</td>
                                <td class="px-3 py-2 text-right text-green-600 font-semibold">
                                    {{ number_format($ca, 0, ',', ' ') }} FCFA
                                </td>
                                <td class="px-3 py-2 text-right text-blue-600 font-semibold">
                                    {{ number_format($comm, 0, ',', ' ') }} FCFA
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <span class="px-2 py-1 bg-gray-100 rounded text-xs">
                                        {{ number_format($percentage, 2) }}%
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                                    Aucune donnée disponible
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
