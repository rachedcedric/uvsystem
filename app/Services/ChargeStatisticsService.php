<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Commission;
use Carbon\Carbon;

/**
 * Source unique de calcul pour la page "Gestion des Charges" : revenu
 * (commissions déjà générées par les ventes — jamais le chiffre d'affaires),
 * charges d'exploitation, et revenu net. Réutilisée par la page Filament et
 * par les exports, pour ne jamais avoir qu'une seule formule.
 */
class ChargeStatisticsService
{
    public const PERIOD_TODAY = 'today';

    public const PERIOD_THIS_WEEK = 'this_week';

    public const PERIOD_THIS_MONTH = 'this_month';

    public const PERIOD_LAST_MONTH = 'last_month';

    public const PERIOD_CUSTOM = 'custom';

    /**
     * Résout une clé de période (+ bornes personnalisées éventuelles) en un
     * intervalle [début, fin] concret et un libellé lisible. 'this_month'
     * (mois en cours) est le repli par défaut pour toute clé inconnue/absente.
     *
     * @return array{start: Carbon, end: Carbon, label: string}
     */
    public function resolvePeriod(?string $filterKey, ?string $customFrom = null, ?string $customUntil = null): array
    {
        $now = Carbon::now();

        switch ($filterKey) {
            case self::PERIOD_TODAY:
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                $label = "Aujourd'hui : ".$start->format('d/m/Y');
                break;

            case self::PERIOD_THIS_WEEK:
                $start = $now->copy()->startOfWeek();
                $end = $now->copy()->endOfWeek();
                $label = 'Cette semaine : du '.$start->format('d/m/Y').' au '.$end->format('d/m/Y');
                break;

            case self::PERIOD_LAST_MONTH:
                $start = $now->copy()->subMonthNoOverflow()->startOfMonth();
                $end = $now->copy()->subMonthNoOverflow()->endOfMonth();
                $label = 'Mois précédent : du '.$start->format('d/m/Y').' au '.$end->format('d/m/Y');
                break;

            case self::PERIOD_CUSTOM:
                $start = $customFrom ? Carbon::parse($customFrom)->startOfDay() : $now->copy()->startOfMonth();
                $end = $customUntil ? Carbon::parse($customUntil)->endOfDay() : $now->copy()->endOfDay();
                $label = 'Période : du '.$start->format('d/m/Y').' au '.$end->format('d/m/Y');
                break;

            case self::PERIOD_THIS_MONTH:
            default:
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                $label = 'Mois en cours : du '.$start->format('d/m/Y').' au '.$end->format('d/m/Y');
                break;
        }

        return ['start' => $start, 'end' => $end, 'label' => $label];
    }

    /**
     * Total Revenu = somme des commissions globales déjà générées par les
     * ventes sur la période — jamais un recalcul, jamais SUM(ventes).
     * Même requête que MonthlyPerformanceWidget (source de vérité unique).
     */
    public function totalRevenue(Carbon $start, Carbon $end): float
    {
        // Important : ne jamais tronquer $start/$end en simple date ('Y-m-d')
        // ici. date_commission est castée 'date', ce qui la stocke en base
        // avec un suffixe horaire ("2026-06-15 00:00:00") — une borne
        // supérieure tronquée ("2026-06-15") exclurait alors, par
        // comparaison lexicographique, la ligne du dernier jour de la
        // période. Passer les Carbon complets (fin de journée incluse par
        // resolvePeriod()) évite ce piège, comme le fait déjà
        // MonthlyPerformanceWidget.
        return (float) Commission::globales()
            ->forPeriod($start, $end)
            ->sum('commission_globale');
    }

    /**
     * Total Charges = somme des charges d'exploitation enregistrées sur la
     * période (toutes catégories confondues — le filtre catégorie ne
     * s'applique qu'au tableau de détail, jamais à ce total).
     */
    public function totalCharges(Carbon $start, Carbon $end): float
    {
        return (float) Charge::query()->forPeriod($start, $end)->sum('montant');
    }

    public function netRevenue(Carbon $start, Carbon $end): float
    {
        return $this->totalRevenue($start, $end) - $this->totalCharges($start, $end);
    }

    /**
     * @return array{revenue: float, charges: float, net: float, period_label: string, start: Carbon, end: Carbon}
     */
    public function summary(?string $filterKey, ?string $customFrom = null, ?string $customUntil = null): array
    {
        $period = $this->resolvePeriod($filterKey, $customFrom, $customUntil);

        $revenue = $this->totalRevenue($period['start'], $period['end']);
        $charges = $this->totalCharges($period['start'], $period['end']);

        return [
            'revenue' => $revenue,
            'charges' => $charges,
            'net' => $revenue - $charges,
            'period_label' => $period['label'],
            'start' => $period['start'],
            'end' => $period['end'],
        ];
    }
}
