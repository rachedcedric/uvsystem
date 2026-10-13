<?php

namespace App\Support\Mobile;

use App\Services\ChargeStatisticsService;
use Carbon\Carbon;

/**
 * Périodes de la page Charges (mêmes bornes que le filtre de la page « Gestion des Charges » du bureau),
 * partagées entre les cartes de l'écran et les exports pour que leurs totaux soient identiques.
 */
final class ChargePeriods
{
    /** @var array<string, string> clé mobile => libellé */
    public const LABELS = [
        'today' => 'Aujourd\'hui',
        'week' => 'Cette semaine',
        'month' => 'Ce mois',
        'last' => 'Mois précédent',
        'all' => 'Tout',
    ];

    private const KEYS = [
        'today' => ChargeStatisticsService::PERIOD_TODAY,
        'week' => ChargeStatisticsService::PERIOD_THIS_WEEK,
        'month' => ChargeStatisticsService::PERIOD_THIS_MONTH,
        'last' => ChargeStatisticsService::PERIOD_LAST_MONTH,
    ];

    /**
     * Revenu / Charges / Net et bornes de la période (« all » = depuis le début jusqu'à aujourd'hui).
     *
     * @return array{revenue: float, charges: float, net: float, period_label: string, start: Carbon, end: Carbon}
     */
    public static function summary(string $period): array
    {
        $service = app(ChargeStatisticsService::class);

        if ($period === 'all') {
            return $service->summary(ChargeStatisticsService::PERIOD_CUSTOM, '2000-01-01', now()->toDateString());
        }

        return $service->summary(self::KEYS[$period] ?? ChargeStatisticsService::PERIOD_THIS_MONTH);
    }
}
