<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Résumé : widget natif (StatsOverviewWidget), aligné comme les
        autres widgets du projet (Dashboard, Ventes UV, Commissions). --}}
        @livewire(\App\Filament\Widgets\DettesGlobalStatsWidget::class)

        {{-- Table des dettes --}}
        {{ $this->table }}
    </div>
</x-filament-panels::page>
