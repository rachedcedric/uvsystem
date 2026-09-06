<x-filament-panels::page>
    <div class="space-y-6">
        @if ($agent)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                    Historique de vos remboursements
                </h2>
                {{ $this->table }}
            </div>
        @else
            <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-xl p-8 text-center">
                @svg('heroicon-o-exclamation-triangle', 'w-12 h-12 mx-auto text-yellow-500 mb-4')
                <h3 class="text-lg font-semibold text-yellow-900 dark:text-yellow-100 mb-2">
                    Compte agent non configuré
                </h3>
                <p class="text-yellow-800 dark:text-yellow-200">
                    Votre compte utilisateur n'est pas encore lié à un profil agent.
                </p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
