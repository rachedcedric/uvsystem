<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Stats rapides --}}
        @php($summary = \App\Filament\Pages\DettesPage::getSummary())
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-filament::section class="h-full">
                <div class="flex h-full items-center gap-4">
                    <div class="p-3 rounded-full bg-primary-100 dark:bg-primary-900">
                        <x-heroicon-o-bell class="w-6 h-6 text-primary-600 dark:text-primary-400" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">
                            {{ $summary['count'] }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Agents endettés</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section class="h-full">
                <div class="flex h-full items-center gap-4">
                    <div class="p-3 rounded-full bg-danger-100 dark:bg-danger-900">
                        <x-heroicon-o-bell-alert class="w-6 h-6 text-danger-600 dark:text-danger-400" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-danger-600 dark:text-danger-400">
                            {{ number_format($summary['debtToday'], 0, ',', ' ') }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Dettes du jour (FCFA)</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section class="h-full">
                <div class="flex h-full items-center gap-4">
                    <div class="p-3 rounded-full bg-success-100 dark:bg-success-900">
                        <x-heroicon-o-check-circle class="w-6 h-6 text-success-600 dark:text-success-400" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-success-600 dark:text-success-400">
                            {{ number_format($summary['repaidToday'], 0, ',', ' ') }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Remboursements du jour (FCFA)</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section class="h-full">
                <div class="flex h-full items-center gap-4">
                    <div class="p-3 rounded-full bg-warning-100 dark:bg-warning-900">
                        <x-heroicon-o-clock class="w-6 h-6 text-warning-600 dark:text-warning-400" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-warning-600 dark:text-warning-400">
                            {{ number_format($summary['outstanding'], 0, ',', ' ') }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Dette non remboursée (FCFA)</p>
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- Table des notifications --}}
        {{ $this->table }}
    </div>
</x-filament-panels::page>
