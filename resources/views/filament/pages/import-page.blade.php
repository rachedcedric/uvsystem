<x-filament-panels::page>
    <form wire:submit.prevent="import">
        {{ $this->form }}
        
        <div class="mt-6 flex gap-4">
            <x-filament::button type="submit" size="lg">
                @svg("heroicon-o-arrow-up-tray", "w-5 h-5 mr-2")
                Importer
            </x-filament::button>
            
            <x-filament::button type="button" color="gray" wire:click="downloadTemplate">
                @svg("heroicon-o-document-arrow-down", "w-5 h-5 mr-2")
                Télécharger Modèle
            </x-filament::button>
        </div>
    </form>

    @if($importResults)
        <div class="mt-8">
            @if(isset($importResults['error']))
                <x-filament::section>
                    <x-slot name="heading">
                        <span class="text-danger-600">❌ Erreur d'Import</span>
                    </x-slot>
                    <p class="text-danger-600">{{ $importResults['error'] }}</p>
                </x-filament::section>
            @else
                <x-filament::section>
                    <x-slot name="heading">
                        📊 Résultats de l'Import
                    </x-slot>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <div class="bg-gray-100 dark:bg-gray-800 rounded-lg p-4 text-center">
                            <div class="text-3xl font-bold text-gray-900 dark:text-gray-100">
                                {{ $importResults['total_rows'] }}
                            </div>
                            <div class="text-sm text-gray-500">Lignes traitées</div>
                        </div>
                        
                        <div class="bg-success-50 dark:bg-success-900/20 rounded-lg p-4 text-center">
                            <div class="text-3xl font-bold text-success-600">
                                {{ $importResults['success'] }}
                            </div>
                            <div class="text-sm text-success-600">Importées</div>
                        </div>
                        
                        <div class="bg-danger-50 dark:bg-danger-900/20 rounded-lg p-4 text-center">
                            <div class="text-3xl font-bold text-danger-600">
                                {{ $importResults['errors'] }}
                            </div>
                            <div class="text-sm text-danger-600">Erreurs</div>
                        </div>
                    </div>

                    @if(count($importResults['failures'] ?? []) > 0)
                        <div class="mt-4">
                            <h4 class="font-medium text-gray-900 dark:text-gray-100 mb-2">
                                Détail des erreurs (max 10) :
                            </h4>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-100 dark:bg-gray-800">
                                        <tr>
                                            <th class="px-4 py-2 text-left">Ligne</th>
                                            <th class="px-4 py-2 text-left">Champ</th>
                                            <th class="px-4 py-2 text-left">Erreur</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($importResults['failures'] as $failure)
                                            <tr class="border-b dark:border-gray-700">
                                                <td class="px-4 py-2">{{ $failure['row'] }}</td>
                                                <td class="px-4 py-2">{{ $failure['attribute'] }}</td>
                                                <td class="px-4 py-2 text-danger-600">
                                                    {{ implode(', ', $failure['errors']) }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </x-filament::section>
            @endif
        </div>
    @endif
</x-filament-panels::page>
