<?php

namespace App\Filament\Pages;

use App\Models\Agent;
use App\Models\Commission;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use App\Services\Subscriptions\SubscriptionEntitlements;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class RapportStatistiques extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Rapports & Statistiques';

    protected static ?string $navigationGroup = 'Gestion des Ventes';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.rapport-statistiques';

    public ?array $data = [];

    public $dateFrom;

    public $dateTo;

    public function mount(): void
    {
        app(SubscriptionEntitlements::class)->abortUnlessFeature('statistiques_avancees');

        $this->dateFrom = now()->startOfMonth();
        $this->dateTo = now();
        $this->form->fill([
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Filtres')
                    ->schema([
                        DatePicker::make('date_from')
                            ->label('Du')
                            ->default(now()->startOfMonth())
                            ->reactive(),
                        DatePicker::make('date_to')
                            ->label('Au')
                            ->default(now())
                            ->reactive(),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function getStats(): array
    {
        $dateFrom = $this->data['date_from'] ?? now()->startOfMonth();
        $dateTo = $this->data['date_to'] ?? now();

        // CA Total (ventes UV aux agents - distributions)
        $caTotal = UvTransaction::where('transaction_type', 'distribution')
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->sum('amount');

        // Commissions Total
        $commissionsTotal = Commission::whereBetween('date_commission', [$dateFrom, $dateTo])->sum('montant_commission');

        // Dette Totale (depuis les agents)
        $detteTotale = Agent::sum('current_debt');

        // Remboursements
        $remboursementsTotal = Remboursement::whereBetween('payment_date', [$dateFrom, $dateTo])->sum('amount');

        // Nombre de ventes (distributions)
        $nbVentes = UvTransaction::where('transaction_type', 'distribution')
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->count();

        return [
            'ca_total' => $caTotal,
            'commissions_total' => $commissionsTotal,
            'dette_totale' => $detteTotale,
            'remboursements_total' => $remboursementsTotal,
            'nb_ventes' => $nbVentes,
            'commission_moyenne' => $nbVentes > 0 ? $commissionsTotal / $nbVentes : 0,
        ];
    }

    public function getChartData(): array
    {
        $dateFrom = $this->data['date_from'] ?? now()->startOfMonth();
        $dateTo = $this->data['date_to'] ?? now();

        // CA par jour (distributions)
        $caParJour = UvTransaction::select(
            DB::raw('DATE(transaction_date) as date'),
            DB::raw('SUM(amount) as total')
        )
            ->where('transaction_type', 'distribution')
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Commissions par jour
        $commissionsParJour = Commission::select(
            DB::raw('DATE(date_commission) as date'),
            DB::raw('SUM(montant_commission) as total')
        )
            ->whereBetween('date_commission', [$dateFrom, $dateTo])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'ca_par_jour' => $caParJour,
            'commissions_par_jour' => $commissionsParJour,
        ];
    }

    public function getTopAgents(): array
    {
        $dateFrom = $this->data['date_from'] ?? now()->startOfMonth();
        $dateTo = $this->data['date_to'] ?? now();

        return UvTransaction::select('agent_id', DB::raw('SUM(amount) as total_ca'))
            ->where('transaction_type', 'distribution')
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->with('agent')
            ->groupBy('agent_id')
            ->orderByDesc('total_ca')
            ->limit(10)
            ->get()
            ->toArray();
    }
}
