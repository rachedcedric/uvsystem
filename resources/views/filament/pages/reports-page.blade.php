<x-filament-panels::page>
    <div class="space-y-6">
        @include('filament.partials.report-catalog', ['data' => $this->reportData()])

        <h2 class="pt-4 text-lg font-semibold text-gray-950 dark:text-white">Exports détaillés</h2>

        {{-- Export Transactions --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    @svg("heroicon-o-banknotes", "w-5 h-5 text-blue-500")
                    <span>Export Transactions UV</span>
                </div>
            </x-slot>
            
            <x-slot name="description">
                Exportez les transactions UV avec filtres personnalisés
            </x-slot>

            {{ $this->transactionsForm }}
            
            <div class="mt-4 flex gap-3">
                <x-filament::button 
                    wire:click="exportTransactionsExcel" 
                    color="primary" 
                    icon="heroicon-o-table-cells"
                >
                    Excel
                </x-filament::button>
                
                <x-filament::button 
                    wire:click="exportTransactionsPdf" 
                    color="danger" 
                    icon="heroicon-o-document"
                >
                    PDF
                </x-filament::button>
            </div>
        </x-filament::section>

        {{-- Export Remboursements --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    @svg("heroicon-o-currency-dollar", "w-5 h-5 text-green-500")
                    <span>Export Remboursements</span>
                </div>
            </x-slot>
            
            <x-slot name="description">
                Exportez les remboursements avec filtres personnalisés
            </x-slot>

            {{ $this->remboursementsForm }}
            
            <div class="mt-4 flex gap-3">
                <x-filament::button 
                    wire:click="exportRemboursementsExcel" 
                    color="success" 
                    icon="heroicon-o-table-cells"
                >
                    Excel
                </x-filament::button>
            </div>
        </x-filament::section>

        {{-- Export Situation Agents --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    @svg("heroicon-o-users", "w-5 h-5 text-purple-500")
                    <span>Export Situation Agents</span>
                </div>
            </x-slot>
            
            <x-slot name="description">
                Exportez la situation financière de tous les agents
            </x-slot>

            {{ $this->agentsForm }}
            
            <div class="mt-4 flex gap-3">
                <x-filament::button 
                    wire:click="exportAgentsExcel" 
                    color="warning" 
                    icon="heroicon-o-table-cells"
                >
                    Excel
                </x-filament::button>
                
                <x-filament::button 
                    wire:click="exportAgentsPdf" 
                    color="danger" 
                    icon="heroicon-o-document"
                >
                    PDF
                </x-filament::button>
            </div>
        </x-filament::section>

    </div>
</x-filament-panels::page>
