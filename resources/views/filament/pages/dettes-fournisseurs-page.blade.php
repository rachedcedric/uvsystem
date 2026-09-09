<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Résumé : widget natif (StatsOverviewWidget), aligné comme les
        autres widgets du projet (Dashboard, Ventes UV, Commissions, Dettes). --}}
        @livewire(\App\Filament\Widgets\FournisseurDebtStatsWidget::class)

        {{-- Table des fournisseurs à crédit --}}
        {{ $this->table }}
    </div>
</x-filament-panels::page>
