<?php

namespace App\Filament\Widgets;

use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Commission;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MonthlyPerformanceWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    protected static ?string $pollingInterval = '60s';

    protected function getHeading(): ?string
    {
        return '📆 Performance Mensuelle';
    }

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $debutMois = Carbon::now()->startOfMonth();
        $finMois = Carbon::now()->endOfMonth();
        $debutMoisPrecedent = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $finMoisPrecedent = Carbon::now()->subMonthNoOverflow()->endOfMonth();

        // Ventes du mois
        $ventesMois = (float) UvTransaction::where('transaction_type', 'distribution')
            ->whereBetween('transaction_date', [$debutMois, $finMois])->sum('amount');
        $ventesMoisPrecedent = (float) UvTransaction::where('transaction_type', 'distribution')
            ->whereBetween('transaction_date', [$debutMoisPrecedent, $finMoisPrecedent])->sum('amount');
        $nbVentesMois = UvTransaction::where('transaction_type', 'distribution')
            ->whereBetween('transaction_date', [$debutMois, $finMois])->count();
        $nbVentesMoisPrecedent = UvTransaction::where('transaction_type', 'distribution')
            ->whereBetween('transaction_date', [$debutMoisPrecedent, $finMoisPrecedent])->count();

        // Approvisionnements du mois
        $approMois = (float) Approvisionnement::whereBetween('date_approvisionnement', [$debutMois, $finMois])->sum('amount');
        $approMoisPrecedent = (float) Approvisionnement::whereBetween('date_approvisionnement', [$debutMoisPrecedent, $finMoisPrecedent])->sum('amount');

        // Remboursements du mois
        $remboursementsMois = (float) Remboursement::whereBetween('payment_date', [$debutMois, $finMois])->sum('amount');
        $remboursementsMoisPrecedent = (float) Remboursement::whereBetween('payment_date', [$debutMoisPrecedent, $finMoisPrecedent])->sum('amount');

        // Commissions générées du mois (commissions globales réellement calculées)
        $commissionsMois = (float) Commission::globales()
            ->whereBetween('date_commission', [$debutMois, $finMois])->sum('commission_globale');
        $commissionsMoisPrecedent = (float) Commission::globales()
            ->whereBetween('date_commission', [$debutMoisPrecedent, $finMoisPrecedent])->sum('commission_globale');

        // Dettes en cours (situation actuelle vs situation au début du mois)
        $detteActuelle = (float) Agent::active()->withDebt()->sum('current_debt');
        $detteDebutMois = Agent::totalDetteActiveAsOf($debutMois->copy()->subSecond());

        return [
            $this->buildStat('Ventes du mois (CA)', $ventesMois, $ventesMoisPrecedent, 'heroicon-o-shopping-cart'),
            $this->buildStat('Approvisionnements du mois', $approMois, $approMoisPrecedent, 'heroicon-o-truck'),
            $this->buildStat('Remboursements du mois', $remboursementsMois, $remboursementsMoisPrecedent, 'heroicon-o-arrow-uturn-left'),
            $this->buildStat('Commissions générées', $commissionsMois, $commissionsMoisPrecedent, 'heroicon-o-star'),

            Stat::make('Transactions (ventes)', number_format($nbVentesMois, 0, ',', ' '))
                ->description($this->variationLabel((float) $nbVentesMois, (float) $nbVentesMoisPrecedent).' vs mois précédent')
                ->descriptionIcon($this->trendIcon((float) $nbVentesMois, (float) $nbVentesMoisPrecedent))
                ->icon('heroicon-o-receipt-percent')
                ->color($this->trendColor((float) $nbVentesMois, (float) $nbVentesMoisPrecedent)),

            $this->buildStat('Dettes en cours', $detteActuelle, $detteDebutMois, 'heroicon-o-exclamation-triangle', invertColors: true, comparisonLabel: 'vs début du mois'),
        ];
    }

    private function buildStat(
        string $label,
        float $valeurActuelle,
        float $valeurPrecedente,
        string $icon,
        bool $invertColors = false,
        string $comparisonLabel = 'vs mois précédent'
    ): Stat {
        $trend = $this->trend($valeurActuelle, $valeurPrecedente);

        $goodTrend = $invertColors ? 'down' : 'up';
        $badTrend = $invertColors ? 'up' : 'down';

        $color = match (true) {
            $trend === $goodTrend => 'success',
            $trend === $badTrend => 'danger',
            default => 'gray',
        };

        return Stat::make($label, number_format($valeurActuelle, 0, ',', ' ').' FCFA')
            ->description($this->variationLabel($valeurActuelle, $valeurPrecedente).' '.$comparisonLabel)
            ->descriptionIcon($this->trendIcon($valeurActuelle, $valeurPrecedente))
            ->icon($icon)
            ->color($color);
    }

    private function pct(float $actuel, float $precedent): float
    {
        if ($precedent == 0.0) {
            return $actuel == 0.0 ? 0.0 : 100.0;
        }

        return (($actuel - $precedent) / $precedent) * 100;
    }

    private function trend(float $actuel, float $precedent): string
    {
        $pct = $this->pct($actuel, $precedent);

        return $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'stable');
    }

    private function trendIcon(float $actuel, float $precedent): string
    {
        return match ($this->trend($actuel, $precedent)) {
            'up' => 'heroicon-m-arrow-trending-up',
            'down' => 'heroicon-m-arrow-trending-down',
            default => 'heroicon-m-minus',
        };
    }

    private function trendColor(float $actuel, float $precedent, bool $invert = false): string
    {
        $trend = $this->trend($actuel, $precedent);
        $goodTrend = $invert ? 'down' : 'up';
        $badTrend = $invert ? 'up' : 'down';

        return match (true) {
            $trend === $goodTrend => 'success',
            $trend === $badTrend => 'danger',
            default => 'gray',
        };
    }

    private function variationLabel(float $actuel, float $precedent): string
    {
        $pct = $this->pct($actuel, $precedent);
        $sign = $pct > 0 ? '+' : '';

        return $sign.number_format($pct, 1, ',', ' ').' %';
    }
}
