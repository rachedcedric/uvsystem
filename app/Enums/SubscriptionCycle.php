<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/** Périodicité de facturation d'un abonnement. */
enum SubscriptionCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensuel',
            self::Yearly => 'Annuel',
        };
    }

    /** Fin de la période qui commence à $start (sans débordement : 31 janvier + 1 mois = 28/29 février). */
    public function endOfPeriodStartingAt(CarbonInterface $start): CarbonInterface
    {
        return match ($this) {
            self::Monthly => $start->copy()->addMonthNoOverflow(),
            self::Yearly => $start->copy()->addYearNoOverflow(),
        };
    }

    public function monthsInCycle(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Yearly => 12,
        };
    }

    /** @return array<string, string> valeur => libellé, pour les listes déroulantes */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
