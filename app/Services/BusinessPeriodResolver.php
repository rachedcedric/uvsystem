<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Bornes prêtes à requêter pour toute métrique "aujourd'hui / cette semaine /
 * ce mois" qui doit respecter le jour métier (cutoff de commission), jamais
 * le jour calendaire. Ne recalcule jamais la règle de cutoff elle-même :
 * délègue entièrement à CommissionBusinessDateResolver (source unique).
 *
 * Tout code qui a besoin de "les ventes d'aujourd'hui / de cette semaine /
 * de ce mois" doit passer par ici plutôt que par un whereDate(...) en dur,
 * pour que la même règle de cutoff s'applique partout (ventes, Top 5,
 * volume de commission en cours).
 */
class BusinessPeriodResolver
{
    public function __construct(
        protected CommissionBusinessDateResolver $dateResolver = new CommissionBusinessDateResolver
    ) {}

    /**
     * @return array{start: Carbon, end: Carbon, businessDate: Carbon}
     */
    public function today(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return $this->rangeFor($this->currentBusinessDate($now), $now);
    }

    /**
     * @return array{start: Carbon, end: Carbon, businessDate: Carbon}
     */
    public function yesterday(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $businessDate = $this->currentBusinessDate($now)->copy()->subDay();

        return $this->rangeFor($businessDate, $now);
    }

    /**
     * @return array{start: Carbon, end: Carbon, businessDate: Carbon}
     */
    public function thisWeek(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $businessDate = $this->currentBusinessDate($now);
        $startBusinessDate = $businessDate->copy()->startOfWeek();

        [$start] = $this->dateResolver->periodFor($startBusinessDate);

        return [
            'start' => $start,
            'end' => $now->copy(),
            'businessDate' => $businessDate,
        ];
    }

    /**
     * @return array{start: Carbon, end: Carbon, businessDate: Carbon}
     */
    public function thisMonth(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $businessDate = $this->currentBusinessDate($now);
        $startBusinessDate = $businessDate->copy()->startOfMonth();

        [$start] = $this->dateResolver->periodFor($startBusinessDate);

        return [
            'start' => $start,
            'end' => $now->copy(),
            'businessDate' => $businessDate,
        ];
    }

    /**
     * Prochaine échéance de clôture (18h02 métier) : celle qui clôturera la
     * journée métier en cours.
     */
    public function nextCutoff(?Carbon $now = null): Carbon
    {
        $now ??= Carbon::now();
        $businessDate = $this->currentBusinessDate($now);

        [, $fin] = $this->dateResolver->periodFor($businessDate);

        return $fin->copy()->addSecond();
    }

    public function currentBusinessDate(?Carbon $now = null): Carbon
    {
        return $this->dateResolver->resolve($now ?? Carbon::now());
    }

    /**
     * @return array{start: Carbon, end: Carbon, businessDate: Carbon}
     */
    protected function rangeFor(Carbon $businessDate, Carbon $now): array
    {
        [$start, $finReelle] = $this->dateResolver->periodFor($businessDate);

        // Journée métier encore ouverte : plafonner la fin à l'instant
        // présent (pas de ventes futures) ; sinon utiliser la fin réelle.
        $end = $this->dateResolver->isClosed($businessDate, $now) ? $finReelle : $now->copy();

        return [
            'start' => $start,
            'end' => $end,
            'businessDate' => $businessDate,
        ];
    }
}
