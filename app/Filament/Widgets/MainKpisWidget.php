<?php

namespace App\Filament\Widgets;

use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Commission;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use App\Services\CommissionService;
use App\Services\MasterSoldeService;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MainKpisWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '15s';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();
        $endOfYesterday = $yesterday->copy()->endOfDay();

        $masterSoldeService = app(MasterSoldeService::class);
        $commissionService = app(CommissionService::class);

        // 1. Solde UV Disponible
        $soldeActuel = $masterSoldeService->getAvailableBalance();
        $soldeHier = $masterSoldeService->getAvailableBalance(asOf: $endOfYesterday);

        // 2. Ventes aujourd'hui
        $ventesAujourdhui = (float) UvTransaction::whereDate('transaction_date', $today)
            ->where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->sum('amount');
        $ventesHier = (float) UvTransaction::whereDate('transaction_date', $yesterday)
            ->where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->sum('amount');

        // 3. Approvisionnements aujourd'hui
        $approAujourdhui = (float) Approvisionnement::whereDate('date_approvisionnement', $today)->sum('amount');
        $approHier = (float) Approvisionnement::whereDate('date_approvisionnement', $yesterday)->sum('amount');

        // 4. Dettes en cours (uniquement le restant dû > 0 des agents actifs)
        $detteActuelle = (float) Agent::active()->withDebt()->sum('current_debt');
        $detteHier = Agent::totalDetteActiveAsOf($endOfYesterday);

        // 5. Remboursements aujourd'hui
        $remboursementsAujourdhui = (float) Remboursement::whereDate('payment_date', $today)->sum('amount');
        $remboursementsHier = (float) Remboursement::whereDate('payment_date', $yesterday)->sum('amount');

        // 6. Commission prévisionnelle (règles de commission réelles, par paliers)
        $commissionAujourdhui = $this->commissionPourDate($commissionService, $today);
        $commissionHier = $this->commissionPourDate($commissionService, $yesterday);

        return [
            $this->buildStat(
                'Solde UV Disponible',
                $soldeActuel,
                $soldeHier,
                'heroicon-o-banknotes',
            ),

            $this->buildStat(
                'Ventes aujourd\'hui',
                $ventesAujourdhui,
                $ventesHier,
                'heroicon-o-shopping-cart',
            ),

            $this->buildStat(
                'Approvisionnements aujourd\'hui',
                $approAujourdhui,
                $approHier,
                'heroicon-o-truck',
            ),

            $this->buildStat(
                'Dettes en cours',
                $detteActuelle,
                $detteHier,
                'heroicon-o-exclamation-triangle',
                invertColors: true,
            ),

            $this->buildStat(
                'Remboursements aujourd\'hui',
                $remboursementsAujourdhui,
                $remboursementsHier,
                'heroicon-o-arrow-uturn-left',
            ),

            $this->buildStat(
                'Commission prévisionnelle',
                $commissionAujourdhui,
                $commissionHier,
                'heroicon-o-star',
            ),
        ];
    }

    private function buildStat(string $label, float $valeurAujourdhui, float $valeurHier, string $icon, bool $invertColors = false): Stat
    {
        ['pct' => $pct, 'trend' => $trend] = $this->variation($valeurAujourdhui, $valeurHier);

        $trendIcon = match ($trend) {
            'up' => 'heroicon-m-arrow-trending-up',
            'down' => 'heroicon-m-arrow-trending-down',
            default => 'heroicon-m-minus',
        };

        $goodTrend = $invertColors ? 'down' : 'up';
        $badTrend = $invertColors ? 'up' : 'down';

        $color = match (true) {
            $trend === $goodTrend => 'success',
            $trend === $badTrend => 'danger',
            default => 'gray',
        };

        $sign = $pct > 0 ? '+' : '';
        $description = $sign.number_format($pct, 1, ',', ' ').' % par rapport à hier';

        return Stat::make($label, $this->formatMoney($valeurAujourdhui))
            ->description($description)
            ->descriptionIcon($trendIcon)
            ->icon($icon)
            ->color($color);
    }

    /**
     * @return array{pct: float, trend: 'up'|'down'|'stable'}
     */
    private function variation(float $aujourdhui, float $hier): array
    {
        if ($hier == 0.0) {
            if ($aujourdhui == 0.0) {
                return ['pct' => 0.0, 'trend' => 'stable'];
            }

            return ['pct' => 100.0, 'trend' => 'up'];
        }

        $pct = (($aujourdhui - $hier) / $hier) * 100;
        $trend = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'stable');

        return ['pct' => $pct, 'trend' => $trend];
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }

    /**
     * Commission globale du jour donné : la valeur réellement calculée si elle
     * existe déjà (clôture passée), sinon une projection en direct basée sur le
     * volume de la période en cours et les mêmes paliers de commission.
     */
    private function commissionPourDate(CommissionService $service, Carbon $date): float
    {
        if (Commission::existeCommissionGlobalePour($date)) {
            return (float) Commission::getCommissionGlobalePour($date)->commission_globale;
        }

        [$debut, $fin] = $service->getPeriodeVentes($date);

        $volume = (float) UvTransaction::where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$debut, $fin])
            ->sum('amount');

        return $service->calculerCommissionGlobale($volume);
    }

    protected function getHeading(): ?string
    {
        return '📊 Vue d\'ensemble — '.now()->translatedFormat('l d F Y');
    }
}
