<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @if ($stats)
        {{-- Period Info --}}
        <div class="flex items-center justify-between bg-white dark:bg-gray-900 rounded-lg p-4 mt-4 border border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-2">
                <x-heroicon-o-calendar class="w-5 h-5 text-gray-500" />
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ \Carbon\Carbon::parse($stats['period']['from'])->format('d/m/Y') }}
                    → {{ \Carbon\Carbon::parse($stats['period']['to'])->format('d/m/Y') }}
                    ({{ $stats['period']['days'] }} jours)
                </span>
            </div>
            <button wire:click="loadStats" type="button"
                class="text-primary-600 hover:text-primary-800 text-sm flex items-center gap-1">
                <x-heroicon-o-arrow-path class="w-4 h-4" />
                Actualiser
            </button>
        </div>

        {{-- Main KPI Cards --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mt-6">
            {{-- CA --}}
            <div class="bg-white dark:bg-gray-900 rounded-lg p-5 border border-gray-200 dark:border-gray-700">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Chiffre d'Affaires</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white mt-2">
                            {{ number_format($stats['global']['total_ca'], 0, ',', ' ') }}
                            <span class="text-sm font-normal text-gray-500">FCFA</span>
                        </p>
                        @if ($stats['trends']['ca_trend'] != 0)
                            <p class="text-xs mt-2 {{ $stats['trends']['ca_trend'] > 0 ? 'text-success-600' : 'text-danger-600' }}">
                                {{ $stats['trends']['ca_trend'] > 0 ? '↑' : '↓' }} {{ abs($stats['trends']['ca_trend']) }}% vs période précédente
                            </p>
                        @endif
                    </div>
                    <div class="p-2 bg-success-100 dark:bg-success-900/20 rounded-lg">
                        <x-heroicon-o-banknotes class="w-6 h-6 text-success-600" />
                    </div>
                </div>
            </div>

            {{-- Remboursements --}}
            <div class="bg-white dark:bg-gray-900 rounded-lg p-5 border border-gray-200 dark:border-gray-700">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Remboursements</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white mt-2">
                            {{ number_format($stats['global']['total_rembourse'], 0, ',', ' ') }}
                            <span class="text-sm font-normal text-gray-500">FCFA</span>
                        </p>
                        @if ($stats['trends']['remboursement_trend'] != 0)
                            <p class="text-xs mt-2 {{ $stats['trends']['remboursement_trend'] > 0 ? 'text-success-600' : 'text-danger-600' }}">
                                {{ $stats['trends']['remboursement_trend'] > 0 ? '↑' : '↓' }} {{ abs($stats['trends']['remboursement_trend']) }}% vs période précédente
                            </p>
                        @endif
                    </div>
                    <div class="p-2 bg-info-100 dark:bg-info-900/20 rounded-lg">
                        <x-heroicon-o-arrow-down-tray class="w-6 h-6 text-info-600" />
                    </div>
                </div>
            </div>

            {{-- Taux Recouvrement --}}
            <div class="bg-white dark:bg-gray-900 rounded-lg p-5 border border-gray-200 dark:border-gray-700">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Taux de Recouvrement</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white mt-2">
                            {{ $stats['global']['taux_recouvrement'] }}%
                        </p>
                        <div class="mt-3 bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                            <div class="bg-primary-600 h-2 rounded-full" style="width: {{ min($stats['global']['taux_recouvrement'], 100) }}%"></div>
                        </div>
                    </div>
                    <div class="p-2 bg-primary-100 dark:bg-primary-900/20 rounded-lg">
                        <x-heroicon-o-chart-pie class="w-6 h-6 text-primary-600" />
                    </div>
                </div>
            </div>

            {{-- Dette --}}
            <div class="bg-white dark:bg-gray-900 rounded-lg p-5 border border-gray-200 dark:border-gray-700">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Dette Totale</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white mt-2">
                            {{ number_format($stats['debt']['total_debt'], 0, ',', ' ') }}
                            <span class="text-sm font-normal text-gray-500">FCFA</span>
                        </p>
                        <p class="text-xs text-gray-500 mt-2">{{ $stats['debt']['agents_with_debt'] }} agents endettés</p>
                    </div>
                    <div class="p-2 bg-danger-100 dark:bg-danger-900/20 rounded-lg">
                        <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-danger-600" />
                    </div>
                </div>
            </div>
        </div>

        {{-- Secondary Stats --}}
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mt-4">
            <div class="bg-white dark:bg-gray-900 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Transactions</p>
                <p class="text-xl font-bold text-gray-900 dark:text-white mt-1">
                    {{ number_format($stats['global']['transactions_count']) }}
                </p>
            </div>
            <div class="bg-white dark:bg-gray-900 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Agents Actifs</p>
                <p class="text-xl font-bold text-primary-600 mt-1">
                    {{ $stats['global']['agents_actifs'] }}
                </p>
            </div>
            <div class="bg-white dark:bg-gray-900 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Commissions</p>
                <p class="text-xl font-bold text-gray-900 dark:text-white mt-1">
                    {{ number_format($stats['global']['total_commissions'], 0, ',', ' ') }}
                </p>
            </div>
            <div class="bg-white dark:bg-gray-900 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Approvisionnements</p>
                <p class="text-xl font-bold text-gray-900 dark:text-white mt-1">
                    {{ number_format($stats['global']['total_approvisionnements'], 0, ',', ' ') }}
                </p>
            </div>
            <div class="bg-white dark:bg-gray-900 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">CA Moyen/Jour</p>
                <p class="text-xl font-bold text-gray-900 dark:text-white mt-1">
                    {{ number_format($stats['averages']['avg_daily_ca'], 0, ',', ' ') }}
                </p>
            </div>
        </div>

        {{-- Two Column Layout --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
            {{-- Top Agents --}}
            <x-filament::section>
                <x-slot name="heading">
                    🏆 Top 10 Agents (CA)
                </x-slot>

                <div class="space-y-3">
                    @forelse($stats['top_agents'] as $index => $agent)
                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <div class="flex items-center gap-3">
                                <span class="flex items-center justify-center w-8 h-8 rounded-full text-sm font-bold
                                    {{ $index === 0 ? 'bg-yellow-100 text-yellow-700' : ($index === 1 ? 'bg-gray-200 text-gray-700' : ($index === 2 ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-600')) }}">
                                    {{ $index === 0 ? '🥇' : ($index === 1 ? '🥈' : ($index === 2 ? '🥉' : $index + 1)) }}
                                </span>
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $agent['agent'] }}</p>
                                    <p class="text-xs text-gray-500">{{ $agent['phone'] }}</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-success-600">
                                    {{ number_format($agent['total_ca'], 0, ',', ' ') }} FCFA
                                </p>
                                <p class="text-xs text-gray-500">{{ $agent['tx_count'] }} transactions</p>
                            </div>
                        </div>
                    @empty
                        <p class="text-center text-gray-500 py-8">Aucune transaction</p>
                    @endforelse
                </div>
            </x-filament::section>

            {{-- Debt Analysis --}}
            <x-filament::section>
                <x-slot name="heading">
                    📊 Analyse des Dettes
                </x-slot>

                <div class="space-y-4">
                    {{-- Categories --}}
                    <div class="grid grid-cols-4 gap-3">
                        <div class="p-3 bg-danger-50 dark:bg-danger-900/20 rounded-lg text-center border border-danger-200 dark:border-danger-800">
                            <p class="text-2xl font-bold text-danger-600">{{ $stats['debt']['critical_agents'] }}</p>
                            <p class="text-xs text-danger-600 mt-1">Critiques ≥90%</p>
                        </div>
                        <div class="p-3 bg-warning-50 dark:bg-warning-900/20 rounded-lg text-center border border-warning-200 dark:border-warning-800">
                            <p class="text-2xl font-bold text-warning-600">{{ $stats['debt']['warning_agents'] }}</p>
                            <p class="text-xs text-warning-600 mt-1">Attention ≥70%</p>
                        </div>
                        <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg text-center border border-gray-200 dark:border-gray-700">
                            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $stats['debt']['agents_with_debt'] }}</p>
                            <p class="text-xs text-gray-500 mt-1">Avec Dette</p>
                        </div>
                        <div class="p-3 bg-success-50 dark:bg-success-900/20 rounded-lg text-center border border-success-200 dark:border-success-800">
                            <p class="text-2xl font-bold text-success-600">{{ $stats['debt']['clean_agents'] }}</p>
                            <p class="text-xs text-success-600 mt-1">Sans Dette</p>
                        </div>
                    </div>

                    {{-- Top Debtors --}}
                    @if (count($stats['worst_debtors']) > 0)
                        <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Top 5 Dettes</h4>
                            <div class="space-y-2">
                                @foreach ($stats['worst_debtors'] as $index => $debtor)
                                    <div class="flex items-center justify-between p-2 bg-danger-50 dark:bg-danger-900/20 rounded-lg border border-danger-100 dark:border-danger-900">
                                        <div class="flex items-center gap-2">
                                            <span class="w-6 h-6 flex items-center justify-center bg-danger-100 dark:bg-danger-900 rounded-full text-xs font-bold text-danger-600">
                                                {{ $index + 1 }}
                                            </span>
                                            <div>
                                                <p class="font-medium text-sm text-gray-900 dark:text-white">{{ $debtor['name'] }}</p>
                                                <p class="text-xs text-gray-500">{{ $debtor['phone'] }}</p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-bold text-sm text-danger-600">{{ number_format($debtor['debt'], 0, ',', ' ') }} FCFA</p>
                                            <p class="text-xs text-gray-500">{{ $debtor['percentage'] }}%</p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        </div>

        {{-- Payment Modes --}}
        @if (count($stats['payment_modes']) > 0)
            <x-filament::section class="mt-6">
                <x-slot name="heading">
                    💳 Modes de Paiement
                </x-slot>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    @foreach ($stats['payment_modes'] as $mode)
                        <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                            <div class="flex items-center gap-2 mb-2">
                                @php
                                    $methodLower = strtolower($mode->payment_method ?? '');
                                @endphp
                                @if (str_contains($methodLower, 'cash') || str_contains($methodLower, 'espece'))
                                    <x-heroicon-o-banknotes class="w-5 h-5 text-success-500" />
                                @elseif(str_contains($methodLower, 'mobile') || str_contains($methodLower, 'wave') || str_contains($methodLower, 'orange'))
                                    <x-heroicon-o-device-phone-mobile class="w-5 h-5 text-warning-500" />
                                @else
                                    <x-heroicon-o-building-library class="w-5 h-5 text-info-500" />
                                @endif
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ ucfirst(str_replace('_', ' ', $mode->payment_method ?? 'Autre')) }}
                                </span>
                            </div>
                            <p class="text-xl font-bold text-gray-900 dark:text-white">
                                {{ number_format($mode->total, 0, ',', ' ') }} <span class="text-sm font-normal text-gray-500">FCFA</span>
                            </p>
                            <p class="text-xs text-gray-500 mt-1">{{ $mode->count }} opération(s)</p>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- Averages --}}
        <x-filament::section class="mt-6">
            <x-slot name="heading">
                📈 Moyennes & Indicateurs
            </x-slot>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="p-5 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 text-center">
                    <x-heroicon-o-calculator class="w-8 h-8 mx-auto mb-2 text-gray-500" />
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">
                        {{ number_format($stats['averages']['avg_transaction'], 0, ',', ' ') }} FCFA
                    </p>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Transaction Moyenne</p>
                </div>
                <div class="p-5 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 text-center">
                    <x-heroicon-o-arrow-path class="w-8 h-8 mx-auto mb-2 text-gray-500" />
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">
                        {{ number_format($stats['averages']['avg_remboursement'], 0, ',', ' ') }} FCFA
                    </p>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Remboursement Moyen</p>
                </div>
                <div class="p-5 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 text-center">
                    <x-heroicon-o-calendar-days class="w-8 h-8 mx-auto mb-2 text-gray-500" />
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">
                        {{ number_format($stats['averages']['avg_daily_ca'], 0, ',', ' ') }} FCFA
                    </p>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">CA Journalier Moyen</p>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
