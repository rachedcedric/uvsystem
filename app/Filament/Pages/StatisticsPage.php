<?php

namespace App\Filament\Pages;

use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Commission;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

class StatisticsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Statistiques';

    protected static ?string $title = '📊 Tableau de Bord Analytique';

    protected static ?string $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.statistics-page';

    public ?array $filters = [];

    public ?array $stats = null;

    public function mount(): void
    {
        app(\App\Services\Subscriptions\SubscriptionEntitlements::class)->abortUnlessFeature('statistiques_essentielles');

        $this->filters = [
            'period' => 'month',
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ];

        $this->loadStats();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('🔍 Filtres de Période')
                    ->description('Sélectionnez la période d\'analyse')
                    ->columns(4)
                    ->compact()
                    ->schema([
                        Select::make('period')
                            ->label('Période')
                            ->options([
                                'day' => '📅 Aujourd\'hui',
                                'week' => '📆 Cette semaine',
                                'month' => '🗓️ Ce mois',
                                'quarter' => '📊 Ce trimestre',
                                'year' => '📈 Cette année',
                                'custom' => '⚙️ Personnalisé',
                            ])
                            ->default('month')
                            ->reactive()
                            ->afterStateUpdated(function ($state) {
                                $this->updatePeriodDates($state);
                                $this->loadStats();
                            }),

                        DatePicker::make('from')
                            ->label('Du')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->visible(fn (callable $get) => $get('period') === 'custom')
                            ->reactive()
                            ->afterStateUpdated(fn () => $this->loadStats()),

                        DatePicker::make('to')
                            ->label('Au')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->visible(fn (callable $get) => $get('period') === 'custom')
                            ->reactive()
                            ->afterStateUpdated(fn () => $this->loadStats()),

                        Select::make('agent_id')
                            ->label('Agent')
                            ->options(Agent::active()->pluck('name', 'id'))
                            ->placeholder('👥 Tous les agents')
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(fn () => $this->loadStats()),
                    ]),
            ])
            ->statePath('filters');
    }

    protected function updatePeriodDates(string $period): void
    {
        $this->filters['from'] = match ($period) {
            'day' => now()->toDateString(),
            'week' => now()->startOfWeek()->toDateString(),
            'month' => now()->startOfMonth()->toDateString(),
            'quarter' => now()->startOfQuarter()->toDateString(),
            'year' => now()->startOfYear()->toDateString(),
            default => $this->filters['from'],
        };

        $this->filters['to'] = now()->toDateString();
    }

    public function loadStats(): void
    {
        $from = $this->filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $this->filters['to'] ?? now()->toDateString();
        $agentId = $this->filters['agent_id'] ?? null;

        // Base queries
        $transactionQuery = UvTransaction::whereBetween('transaction_date', [$from, $to]);
        $remboursementQuery = Remboursement::whereBetween('payment_date', [$from, $to]);

        if ($agentId) {
            $transactionQuery->where('agent_id', $agentId);
            $remboursementQuery->where('agent_id', $agentId);
        }

        // Global stats
        $totalCA = (clone $transactionQuery)->sum('amount');
        $totalRembourse = (clone $remboursementQuery)->sum('amount');
        $totalCommissions = Commission::whereBetween('created_at', [$from, $to])->sum('montant_commission');
        $totalApprovisionnements = Approvisionnement::whereBetween('date_approvisionnement', [$from, $to])->sum('amount');

        $globalStats = [
            'transactions_count' => (clone $transactionQuery)->count(),
            'total_uv' => (clone $transactionQuery)->sum('amount'),
            'total_ca' => $totalCA,
            'remboursements_count' => (clone $remboursementQuery)->count(),
            'total_rembourse' => $totalRembourse,
            'total_commissions' => $totalCommissions,
            'total_approvisionnements' => $totalApprovisionnements,
            'agents_actifs' => (clone $transactionQuery)->distinct('agent_id')->count('agent_id'),
            'solde_periode' => $totalRembourse - $totalCA, // Encaissements - Ventes
            'taux_recouvrement' => $totalCA > 0 ? round(($totalRembourse / $totalCA) * 100, 1) : 0,
        ];

        // Daily breakdown
        $dailyData = (clone $transactionQuery)
            ->selectRaw('DATE(transaction_date) as date, SUM(amount) as total, COUNT(*) as uv_count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $dailyRemboursements = (clone $remboursementQuery)
            ->selectRaw('DATE(payment_date) as date, SUM(amount) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // Top agents
        $topAgents = (clone $transactionQuery)
            ->selectRaw('agent_id, SUM(amount) as total_ca, COUNT(*) as total_uv, COUNT(*) as tx_count')
            ->groupBy('agent_id')
            ->orderByDesc('total_ca')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                $agent = Agent::find($item->agent_id);

                return [
                    'agent' => $agent?->name ?? "Agent #{$item->agent_id}",
                    'phone' => $agent?->phone ?? '-',
                    'total_ca' => $item->total_ca,
                    'total_uv' => $item->total_uv,
                    'tx_count' => $item->tx_count,
                    'current_debt' => $agent?->current_debt ?? 0,
                ];
            });

        // Worst debtors
        $worstDebtors = Agent::active()
            ->where('current_debt', '>', 0)
            ->orderByDesc('current_debt')
            ->limit(5)
            ->get()
            ->map(fn ($agent) => [
                'name' => $agent->name,
                'phone' => $agent->phone,
                'debt' => $agent->current_debt,
                'limit' => $agent->debt_limit,
                'percentage' => $agent->debt_limit > 0 ? round(($agent->current_debt / $agent->debt_limit) * 100, 1) : 100,
            ]);

        // Payment modes breakdown
        $paymentModes = (clone $remboursementQuery)
            ->selectRaw('payment_method, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('payment_method')
            ->get();

        // Debt analysis
        $debtAnalysis = [
            'total_debt' => Agent::active()->sum('current_debt'),
            'agents_with_debt' => Agent::withDebt()->count(),
            'critical_agents' => Agent::criticalDebt()->count(),
            'warning_agents' => Agent::warningDebt()->count(),
            'clean_agents' => Agent::active()->where('current_debt', 0)->count(),
        ];

        // Averages
        $averages = [
            'avg_transaction' => $globalStats['transactions_count'] > 0
                ? $globalStats['total_ca'] / $globalStats['transactions_count']
                : 0,
            'avg_remboursement' => $globalStats['remboursements_count'] > 0
                ? $globalStats['total_rembourse'] / $globalStats['remboursements_count']
                : 0,
            'avg_daily_ca' => collect($dailyData)->avg('total') ?? 0,
        ];

        // Trends (comparison with previous period)
        $periodDays = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $previousFrom = Carbon::parse($from)->subDays($periodDays)->toDateString();
        $previousTo = Carbon::parse($from)->subDay()->toDateString();

        $previousCA = UvTransaction::whereBetween('transaction_date', [$previousFrom, $previousTo])->sum('amount');
        $previousRembourse = Remboursement::whereBetween('payment_date', [$previousFrom, $previousTo])->sum('amount');

        $trends = [
            'ca_trend' => $previousCA > 0 ? round((($totalCA - $previousCA) / $previousCA) * 100, 1) : 0,
            'remboursement_trend' => $previousRembourse > 0 ? round((($totalRembourse - $previousRembourse) / $previousRembourse) * 100, 1) : 0,
            'previous_ca' => $previousCA,
            'previous_rembourse' => $previousRembourse,
        ];

        $this->stats = [
            'period' => ['from' => $from, 'to' => $to, 'days' => $periodDays],
            'global' => $globalStats,
            'daily' => $dailyData,
            'daily_remboursements' => $dailyRemboursements,
            'top_agents' => $topAgents,
            'worst_debtors' => $worstDebtors,
            'payment_modes' => $paymentModes,
            'debt' => $debtAnalysis,
            'averages' => $averages,
            'trends' => $trends,
        ];
    }

    public function getViewData(): array
    {
        return [
            'stats' => $this->stats,
        ];
    }
}
