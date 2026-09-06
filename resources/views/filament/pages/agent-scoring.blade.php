<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Légende des grades --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm">
            <h3 class="text-lg font-semibold mb-3">📊 Système de Notation</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div class="flex items-center gap-2">
                    <span
                        class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-green-100 text-green-800 font-bold">A</span>
                    <span>80-100 pts : Excellent</span>
                </div>
                <div class="flex items-center gap-2">
                    <span
                        class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-800 font-bold">B</span>
                    <span>60-79 pts : Bon</span>
                </div>
                <div class="flex items-center gap-2">
                    <span
                        class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-yellow-100 text-yellow-800 font-bold">C</span>
                    <span>40-59 pts : Moyen</span>
                </div>
                <div class="flex items-center gap-2">
                    <span
                        class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-red-100 text-red-800 font-bold">D/E</span>
                    <span>0-39 pts : À améliorer</span>
                </div>
            </div>
        </div>

        {{-- Critères de scoring --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm">
            <h3 class="text-lg font-semibold mb-3">🎯 Critères d'Évaluation</h3>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-xl">📦</span>
                        <span class="font-semibold">Volume (25 pts)</span>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Nombre de transactions sur les 3 derniers mois
                    </p>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-xl">📅</span>
                        <span class="font-semibold">Régularité (20 pts)</span>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Mois d'activité sur les 6 derniers mois</p>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-xl">💰</span>
                        <span class="font-semibold">Paiement (30 pts)</span>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Ratio remboursements / transactions</p>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-xl">📊</span>
                        <span class="font-semibold">Dette (25 pts)</span>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Utilisation du plafond de dette autorisé</p>
                </div>
            </div>
        </div>

        {{-- Table des agents --}}
        {{ $this->table }}
    </div>
</x-filament-panels::page>
