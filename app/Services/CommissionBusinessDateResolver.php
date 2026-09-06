<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Source unique de vérité pour la notion de "journée métier de commission".
 *
 * La journée métier J couvre la période [J-1 cutoff, J cutoff - 1 seconde].
 * Toute vente enregistrée à partir de l'heure de coupure (incluse) appartient
 * à la journée métier suivante. Cette classe est la seule à contenir cette
 * règle : tout code qui a besoin de savoir "à quelle journée de commission
 * appartient cette date/heure ?" doit passer par ici (calcul des
 * commissions, rattrapage, recalcul manuel, historique, tableau de bord).
 */
final class CommissionBusinessDateResolver
{
    /**
     * Détermine la journée métier de commission à laquelle appartient un
     * instant donné (ex : la date/heure d'une vente).
     */
    public function resolve(Carbon $instant): Carbon
    {
        $cutoff = $instant->copy()->setTimeFromTimeString($this->cutoffTime());

        if ($instant->greaterThanOrEqualTo($cutoff)) {
            return $instant->copy()->addDay()->startOfDay();
        }

        return $instant->copy()->startOfDay();
    }

    /**
     * Retourne la période de ventes [début, fin] correspondant à une
     * journée métier de commission donnée.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodFor(Carbon $businessDate): array
    {
        $debut = $businessDate->copy()->subDay()->setTimeFromTimeString($this->cutoffTime());
        $fin = $businessDate->copy()->setTimeFromTimeString($this->cutoffTime())->subSecond();

        return [$debut, $fin];
    }

    /**
     * Une journée métier est considérée clôturée dès que sa période de
     * ventes est entièrement terminée, c'est-à-dire strictement avant la
     * journée métier en cours "maintenant".
     */
    public function isClosed(Carbon $businessDate, ?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return $businessDate->copy()->startOfDay()->lessThan($this->resolve($now));
    }

    public function cutoffTime(): string
    {
        return (string) config('commission.cutoff_time', '18:02:00');
    }
}
