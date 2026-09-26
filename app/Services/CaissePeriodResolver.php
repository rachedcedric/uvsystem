<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Journée de caisse : de 08h30 au lendemain 08h29:59 (Africa/Abidjan).
 * Purement calculée à partir de l'horodatage des mouvements : aucune tâche
 * planifiée n'est nécessaire au basculement de journée.
 */
class CaissePeriodResolver
{
    public const TIMEZONE = 'Africa/Abidjan';

    public const CUTOFF_HOUR = 8;

    public const CUTOFF_MINUTE = 30;

    /** Date (00:00) de la journée de caisse à laquelle appartient $moment. */
    public function businessDateFor(CarbonInterface $moment): Carbon
    {
        return Carbon::instance($moment)
            ->setTimezone(self::TIMEZONE)
            ->subHours(self::CUTOFF_HOUR)
            ->subMinutes(self::CUTOFF_MINUTE)
            ->startOfDay();
    }

    public function currentDate(?CarbonInterface $now = null): Carbon
    {
        return $this->businessDateFor($now ?? Carbon::now(self::TIMEZONE));
    }

    public function yesterdayDate(?CarbonInterface $now = null): Carbon
    {
        return $this->currentDate($now)->subDay();
    }

    /**
     * Bornes [début inclus, fin exclue) de la journée de caisse, exprimées
     * dans le fuseau de l'application (celui des dates stockées).
     *
     * @return array{start: Carbon, end: Carbon}
     */
    public function bounds(CarbonInterface $businessDate): array
    {
        $start = Carbon::create(
            $businessDate->year,
            $businessDate->month,
            $businessDate->day,
            self::CUTOFF_HOUR,
            self::CUTOFF_MINUTE,
            0,
            self::TIMEZONE,
        );

        $tz = config('app.timezone');

        return [
            'start' => $start->copy()->setTimezone($tz),
            'end' => $start->copy()->addDay()->setTimezone($tz),
        ];
    }

    public function endLabelMoment(CarbonInterface $businessDate): Carbon
    {
        return $this->bounds($businessDate)['end']->copy()->subSecond();
    }
}
