@php
    $summary = $this->getSummary();
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Résumé --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <x-filament::section compact>
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-full bg-success-100 dark:bg-success-900">
                        <x-heroicon-o-arrow-trending-up class="w-5 h-5 text-success-600 dark:text-success-400" />
                    </div>
                    <div>
                        <p class="text-xl font-bold text-success-600 dark:text-success-400">{{ number_format($summary['revenue'], 0, ',', ' ') }} FCFA</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Total Revenu</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $summary['period_label'] }}</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section compact>
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-full bg-danger-100 dark:bg-danger-900">
                        <x-heroicon-o-arrow-trending-down class="w-5 h-5 text-danger-600 dark:text-danger-400" />
                    </div>
                    <div>
                        <p class="text-xl font-bold text-danger-600 dark:text-danger-400">{{ number_format($summary['charges'], 0, ',', ' ') }} FCFA</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Total Charges</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section compact>
                <div class="flex items-center gap-3">
                    @if ($summary['net'] > 0)
                        <div class="p-2 rounded-full bg-success-100 dark:bg-success-900">
                            <x-heroicon-o-scale class="w-5 h-5 text-success-600 dark:text-success-400" />
                        </div>
                    @elseif ($summary['net'] < 0)
                        <div class="p-2 rounded-full bg-danger-100 dark:bg-danger-900">
                            <x-heroicon-o-scale class="w-5 h-5 text-danger-600 dark:text-danger-400" />
                        </div>
                    @else
                        <div class="p-2 rounded-full bg-gray-100 dark:bg-gray-800">
                            <x-heroicon-o-scale class="w-5 h-5 text-gray-600 dark:text-gray-400" />
                        </div>
                    @endif
                    <div>
                        @if ($summary['net'] > 0)
                            <p class="text-xl font-bold text-success-600 dark:text-success-400">{{ number_format($summary['net'], 0, ',', ' ') }} FCFA</p>
                        @elseif ($summary['net'] < 0)
                            <p class="text-xl font-bold text-danger-600 dark:text-danger-400">{{ number_format($summary['net'], 0, ',', ' ') }} FCFA</p>
                        @else
                            <p class="text-xl font-bold text-gray-900 dark:text-white">{{ number_format($summary['net'], 0, ',', ' ') }} FCFA</p>
                        @endif
                        <p class="text-xs text-gray-500 dark:text-gray-400">Total Revenu net</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Commissions - Charges</p>
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- Table des charges --}}
        {{ $this->table }}
    </div>
</x-filament-panels::page>
