<?php

namespace App\Filament\Widgets;

use App\Models\Commission;
use App\Services\BusinessPeriodResolver;
use App\Services\CommissionService;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

/**
 * 8 widgets de la page Commissions : Aujourd'hui / Cette semaine / Ce mois /
 * Total cumulé / Commission d'hier / Commission prévisionnelle / Volume du
 * jour (en cours) / Prochain calcul.
 *
 * Toutes les valeurs proviennent de CommissionService/BusinessPeriodResolver
 * (mêmes méthodes que MainKpisWidget) — aucun second calcul.
 *
 * Note : par construction de la règle de cutoff, la journée métier "en
 * cours" n'est jamais close (CommissionBusinessDateResolver::isClosed()
 * n'est vrai qu'une fois que "maintenant" est passé dans la journée
 * suivante) — "Aujourd'hui" et "Commission prévisionnelle" sont donc
 * mathématiquement la même projection tant que la clôture de 18h02 n'est
 * pas passée. Les deux widgets restent affichés séparément (8 widgets
 * demandés) mais appellent la même méthode, jamais deux formules.
 */
class CommissionKpisWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $resolver = app(BusinessPeriodResolver::class);
        $service = app(CommissionService::class);

        $today = $resolver->currentBusinessDate();
        $yesterday = $today->copy()->subDay();
        $weekStart = $today->copy()->startOfWeek();
        $monthStart = $today->copy()->startOfMonth();

        $commissionAujourdhui = $service->commissionPourJourneeMetier($today);
        $commissionHier = $service->commissionPourJourneeMetier($yesterday);
        $commissionSemaine = (float) Commission::globales()->forPeriod($weekStart, $today)->sum('commission_globale');
        $commissionMois = (float) Commission::globales()->forPeriod($monthStart, $today)->sum('commission_globale');
        $commissionCumulee = (float) Commission::globales()->sum('commission_globale');
        $volumeJour = $service->volumePourJourneeMetier($today);
        $prochainCalcul = $resolver->nextCutoff();

        return [
            CompactStat::make("Aujourd'hui", $this->formatMoney($commissionAujourdhui))
                ->description('Journée métier en cours')
                ->icon('heroicon-o-star')
                ->color('primary'),

            CompactStat::make('Cette semaine', $this->formatMoney($commissionSemaine))
                ->icon('heroicon-o-calendar-days')
                ->color('info'),

            CompactStat::make('Ce mois', $this->formatMoney($commissionMois))
                ->icon('heroicon-o-calendar-days')
                ->color('warning'),

            CompactStat::make('Total cumulé', $this->formatMoney($commissionCumulee))
                ->description('Depuis le début')
                ->icon('heroicon-o-archive-box')
                ->color('success'),

            CompactStat::make("Commission d'hier", $this->formatMoney($commissionHier))
                ->description('Dernière journée métier clôturée')
                ->icon('heroicon-o-check-circle')
                ->color('gray'),

            CompactStat::make('Commission prévisionnelle', $this->formatMoney($commissionAujourdhui))
                ->description('Estimation sur le volume en cours, évolue jusqu\'à la clôture')
                ->icon('heroicon-o-chart-bar')
                ->color('primary'),

            CompactStat::make('Volume du jour (en cours)', $this->formatMoney($volumeJour))
                ->description('Ventes accumulées pour la prochaine clôture')
                ->icon('heroicon-o-shopping-cart')
                ->color('info'),

            CompactStat::make('Prochain calcul', $this->formatCutoffLabel($prochainCalcul))
                ->description($prochainCalcul->format('d/m/Y \\à H:i'))
                ->icon('heroicon-o-clock')
                ->color('gray'),
        ];
    }

    private function formatCutoffLabel(Carbon $nextCutoff): string
    {
        $cutoffHeure = $nextCutoff->format('H\\hi');
        $now = Carbon::now();

        if ($nextCutoff->isSameDay($now)) {
            return "Aujourd'hui à {$cutoffHeure}";
        }

        if ($nextCutoff->isSameDay($now->copy()->addDay())) {
            return "Demain à {$cutoffHeure}";
        }

        return $nextCutoff->translatedFormat('d/m/Y').' à '.$cutoffHeure;
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }
}
