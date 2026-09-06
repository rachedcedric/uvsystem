<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Actions --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                Actions de sauvegarde
            </h2>
            
            <div class="flex flex-wrap gap-4">
                <x-filament::button 
                    wire:click="runFullBackup" 
                    wire:loading.attr="disabled"
                    icon="heroicon-o-arrow-up-tray"
                    color="primary"
                >
                    <span wire:loading.remove wire:target="runFullBackup">Backup complet</span>
                    <span wire:loading wire:target="runFullBackup">Backup en cours...</span>
                </x-filament::button>

                <x-filament::button 
                    wire:click="runDbBackup" 
                    wire:loading.attr="disabled"
                    icon="heroicon-o-circle-stack"
                    color="info"
                >
                    <span wire:loading.remove wire:target="runDbBackup">Backup BDD uniquement</span>
                    <span wire:loading wire:target="runDbBackup">Backup en cours...</span>
                </x-filament::button>

                <x-filament::button 
                    wire:click="cleanOldBackups" 
                    icon="heroicon-o-trash"
                    color="warning"
                >
                    Nettoyer anciens backups
                </x-filament::button>

                <x-filament::button 
                    wire:click="loadBackups" 
                    icon="heroicon-o-arrow-path"
                    color="gray"
                >
                    Rafraîchir
                </x-filament::button>
            </div>
        </div>

        {{-- Backup Info --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                <div class="flex items-center gap-4">
                    <div class="p-3 bg-blue-100 dark:bg-blue-900 rounded-lg">
                        @svg("heroicon-o-server", "w-6 h-6 text-blue-600 dark:text-blue-400")
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Total sauvegardes</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ count($backups) }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                <div class="flex items-center gap-4">
                    <div class="p-3 bg-green-100 dark:bg-green-900 rounded-lg">
                        @svg("heroicon-o-clock", "w-6 h-6 text-green-600 dark:text-green-400")
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Dernière sauvegarde</p>
                        <p class="text-lg font-bold text-gray-900 dark:text-white">
                            {{ count($backups) > 0 ? $backups[0]['date'] : 'Aucune' }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                <div class="flex items-center gap-4">
                    <div class="p-3 bg-purple-100 dark:bg-purple-900 rounded-lg">
                        @svg("heroicon-o-folder", "w-6 h-6 text-purple-600 dark:text-purple-400")
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Stockage</p>
                        <p class="text-lg font-bold text-gray-900 dark:text-white">Local</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Backups List --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                    Liste des sauvegardes
                </h2>
            </div>

            @if(count($backups) > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                    Fichier
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                    Taille
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                    Date
                                </th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($backups as $backup)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center gap-3">
                                            @svg("heroicon-o-document-arrow-down", "w-5 h-5 text-gray-400")
                                            <span class="font-medium text-gray-900 dark:text-white">
                                                {{ $backup['name'] }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-gray-600 dark:text-gray-300">
                                        {{ $backup['size'] }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-gray-600 dark:text-gray-300">
                                        {{ $backup['date'] }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <div class="flex justify-end gap-2">
                                            <x-filament::button 
                                                wire:click="downloadBackup('{{ $backup['path'] }}')"
                                                size="sm"
                                                color="success"
                                                icon="heroicon-o-arrow-down-tray"
                                            >
                                                Télécharger
                                            </x-filament::button>
                                            
                                            <x-filament::button 
                                                wire:click="deleteBackup('{{ $backup['path'] }}')"
                                                wire:confirm="Êtes-vous sûr de vouloir supprimer cette sauvegarde ?"
                                                size="sm"
                                                color="danger"
                                                icon="heroicon-o-trash"
                                            >
                                                Supprimer
                                            </x-filament::button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="p-12 text-center">
                    @svg("heroicon-o-archive-box", "w-12 h-12 mx-auto text-gray-400 mb-4")
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">
                        Aucune sauvegarde
                    </h3>
                    <p class="text-gray-500 dark:text-gray-400 mb-4">
                        Lancez votre première sauvegarde pour commencer.
                    </p>
                    <x-filament::button wire:click="runFullBackup" icon="heroicon-o-arrow-up-tray">
                        Créer une sauvegarde
                    </x-filament::button>
                </div>
            @endif
        </div>

        {{-- Schedule Info --}}
        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-6">
            <div class="flex gap-4">
                @svg("heroicon-o-information-circle", "w-6 h-6 text-blue-600 dark:text-blue-400 flex-shrink-0")
                <div>
                    <h3 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">
                        Planification automatique
                    </h3>
                    <ul class="text-sm text-blue-800 dark:text-blue-200 space-y-1">
                        <li>• <strong>Backup complet</strong> : Tous les jours à 02h00</li>
                        <li>• <strong>Backup BDD</strong> : Toutes les 6 heures</li>
                        <li>• <strong>Nettoyage</strong> : Tous les jours à 03h00</li>
                        <li>• <strong>Rétention</strong> : 7 jours complets, 16 jours quotidiens, 8 semaines, 4 mois, 2 ans</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
