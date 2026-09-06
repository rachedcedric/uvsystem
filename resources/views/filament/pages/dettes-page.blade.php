@php
    $summary = $this->getSummary();
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Résumé --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <x-filament::section>
                <div class="flex items-center gap-4">
                    <div class="p-3 rounded-full bg-danger-100 dark:bg-danger-900">
                        <x-heroicon-o-user-group class="w-6 h-6 text-danger-600 dark:text-danger-400" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $summary['count'] }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Agents endettés</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center gap-4">
                    <div class="p-3 rounded-full bg-primary-100 dark:bg-primary-900">
                        <x-heroicon-o-banknotes class="w-6 h-6 text-primary-600 dark:text-primary-400" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($summary['totalInitial'], 0, ',', ' ') }} FCFA</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Total des dettes</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center gap-4">
                    <div class="p-3 rounded-full bg-success-100 dark:bg-success-900">
                        <x-heroicon-o-arrow-uturn-left class="w-6 h-6 text-success-600 dark:text-success-400" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-success-600 dark:text-success-400">{{ number_format($summary['totalRembourse'], 0, ',', ' ') }} FCFA</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Déjà remboursé</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center gap-4">
                    <div class="p-3 rounded-full bg-warning-100 dark:bg-warning-900">
                        <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-warning-600 dark:text-warning-400" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-warning-600 dark:text-warning-400">{{ number_format($summary['totalReste'], 0, ',', ' ') }} FCFA</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Reste à encaisser</p>
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- Table des dettes --}}
        {{ $this->table }}
    </div>
</x-filament-panels::page>
