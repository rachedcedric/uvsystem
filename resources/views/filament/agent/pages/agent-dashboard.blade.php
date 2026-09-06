<x-filament-panels::page>
    <div class="space-y-6">
        @if($agent)
            {{-- Welcome Card --}}
            <div class="bg-gradient-to-r from-emerald-500 to-teal-600 rounded-xl shadow-lg p-6 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold">Bienvenue, {{ $agent->name }} !</h2>
                        <p class="text-emerald-100 mt-1">{{ $agent->phone }}</p>
                    </div>
                    <div class="text-right">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium
                            {{ $agent->status === 'active' ? 'bg-emerald-200 text-emerald-800' : 'bg-red-200 text-red-800' }}">
                            {{ $agent->status === 'active' ? 'Actif' : 'Inactif' }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- Stats Grid --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                    <div class="flex items-center gap-4">
                        <div class="p-3 bg-red-100 dark:bg-red-900 rounded-lg">
                            @svg("heroicon-o-banknotes", "w-6 h-6 text-red-600 dark:text-red-400")
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Dette actuelle</p>
                            <p class="text-2xl font-bold {{ $stats['dette_actuelle'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                                {{ number_format($stats['dette_actuelle'], 0, ',', ' ') }} FCFA
                            </p>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                    <div class="flex items-center gap-4">
                        <div class="p-3 bg-blue-100 dark:bg-blue-900 rounded-lg">
                            @svg("heroicon-o-inbox-arrow-down", "w-6 h-6 text-blue-600 dark:text-blue-400")
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Total UV reçus</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-white">
                                {{ number_format($stats['total_uv'], 0, ',', ' ') }} FCFA
                            </p>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                    <div class="flex items-center gap-4">
                        <div class="p-3 bg-green-100 dark:bg-green-900 rounded-lg">
                            @svg("heroicon-o-arrow-trending-up", "w-6 h-6 text-green-600 dark:text-green-400")
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Total remboursé</p>
                            <p class="text-2xl font-bold text-green-600">
                                {{ number_format($stats['total_remboursements'], 0, ',', ' ') }} FCFA
                            </p>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                    <div class="flex items-center gap-4">
                        <div class="p-3 bg-purple-100 dark:bg-purple-900 rounded-lg">
                            @svg("heroicon-o-credit-card", "w-6 h-6 text-purple-600 dark:text-purple-400")
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Crédit disponible</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-white">
                                @if($stats['credit_disponible'] !== null)
                                    {{ number_format($stats['credit_disponible'], 0, ',', ' ') }} FCFA
                                @else
                                    Illimité
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            @if($stats['plafond'])
                {{-- Debt Progress Bar --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Utilisation du plafond de crédit</h3>
                    <div class="relative pt-1">
                        <div class="flex mb-2 items-center justify-between">
                            <div>
                                <span class="text-xs font-semibold inline-block py-1 px-2 uppercase rounded-full 
                                    {{ $stats['ratio_dette'] > 80 ? 'text-red-600 bg-red-200' : ($stats['ratio_dette'] > 50 ? 'text-yellow-600 bg-yellow-200' : 'text-green-600 bg-green-200') }}">
                                    {{ $stats['ratio_dette'] }}% utilisé
                                </span>
                            </div>
                            <div class="text-right">
                                <span class="text-xs font-semibold text-gray-600 dark:text-gray-400">
                                    Plafond: {{ number_format($stats['plafond'], 0, ',', ' ') }} FCFA
                                </span>
                            </div>
                        </div>
                        <div class="overflow-hidden h-3 text-xs flex rounded-full bg-gray-200 dark:bg-gray-700">
                            <div style="width: {{ min($stats['ratio_dette'], 100) }}%" 
                                class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center transition-all duration-500
                                {{ $stats['ratio_dette'] > 80 ? 'bg-red-500' : ($stats['ratio_dette'] > 50 ? 'bg-yellow-500' : 'bg-green-500') }}">
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Recent Activity --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Recent Transactions --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="font-semibold text-gray-900 dark:text-white">Dernières distributions reçues</h3>
                    </div>
                    @if(count($recentTransactions) > 0)
                        <div class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($recentTransactions as $transaction)
                                <div class="px-6 py-4 flex items-center justify-between">
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-white">{{ $transaction['amount'] }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $transaction['date'] }}</p>
                                    </div>
                                    <span class="text-sm text-gray-600 dark:text-gray-300">
                                        {{ $transaction['quantity'] }} UV
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                            Aucune distribution récente
                        </div>
                    @endif
                </div>

                {{-- Recent Remboursements --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="font-semibold text-gray-900 dark:text-white">Derniers remboursements</h3>
                    </div>
                    @if(count($recentRemboursements) > 0)
                        <div class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($recentRemboursements as $remboursement)
                                <div class="px-6 py-4 flex items-center justify-between">
                                    <div>
                                        <p class="font-medium text-green-600">{{ $remboursement['amount'] }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $remboursement['date'] }}</p>
                                    </div>
                                    <span class="text-sm text-gray-600 dark:text-gray-300">
                                        {{ $remboursement['method'] }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                            Aucun remboursement récent
                        </div>
                    @endif
                </div>
            </div>

            {{-- Contact Info --}}
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-6">
                <div class="flex gap-4">
                    @svg("heroicon-o-information-circle", "w-6 h-6 text-blue-600 dark:text-blue-400 flex-shrink-0")
                    <div>
                        <h3 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">
                            Besoin d'aide ?
                        </h3>
                        <p class="text-sm text-blue-800 dark:text-blue-200">
                            Contactez votre gestionnaire pour toute question concernant votre compte, 
                            vos distributions ou vos remboursements.
                        </p>
                    </div>
                </div>
            </div>
        @else
            {{-- No Agent Linked --}}
            <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-xl p-8 text-center">
                @svg("heroicon-o-exclamation-triangle", "w-12 h-12 mx-auto text-yellow-500 mb-4")
                <h3 class="text-lg font-semibold text-yellow-900 dark:text-yellow-100 mb-2">
                    Compte agent non configuré
                </h3>
                <p class="text-yellow-800 dark:text-yellow-200">
                    Votre compte utilisateur n'est pas encore lié à un profil agent.
                    Veuillez contacter l'administrateur pour configurer votre accès.
                </p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
