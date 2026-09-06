<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Stats rapides --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <x-filament::section>
                <div class="flex items-center gap-4">
                    <div class="p-3 rounded-full bg-primary-100 dark:bg-primary-900">
                        <x-heroicon-o-bell class="w-6 h-6 text-primary-600 dark:text-primary-400" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">
                            {{ auth()->user()->notifications->count() }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Total</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center gap-4">
                    <div class="p-3 rounded-full bg-danger-100 dark:bg-danger-900">
                        <x-heroicon-o-bell-alert class="w-6 h-6 text-danger-600 dark:text-danger-400" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-danger-600 dark:text-danger-400">
                            {{ auth()->user()->unreadNotifications->count() }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Non lues</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center gap-4">
                    <div class="p-3 rounded-full bg-success-100 dark:bg-success-900">
                        <x-heroicon-o-check-circle class="w-6 h-6 text-success-600 dark:text-success-400" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-success-600 dark:text-success-400">
                            {{ auth()->user()->readNotifications->count() }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Lues</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center gap-4">
                    <div class="p-3 rounded-full bg-warning-100 dark:bg-warning-900">
                        <x-heroicon-o-clock class="w-6 h-6 text-warning-600 dark:text-warning-400" />
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-warning-600 dark:text-warning-400">
                            {{ auth()->user()->notifications()->whereDate('created_at', today())->count() }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Aujourd'hui</p>
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- Table des notifications --}}
        {{ $this->table }}
    </div>
</x-filament-panels::page>
