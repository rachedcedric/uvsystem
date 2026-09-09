<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Résumé : widget natif (StatsOverviewWidget), aligné comme les
        autres widgets du projet. --}}
        @livewire(\App\Filament\Widgets\CaisseStatsWidget::class)

        {{-- Historique des mouvements --}}
        {{ $this->table }}
    </div>
</x-filament-panels::page>
