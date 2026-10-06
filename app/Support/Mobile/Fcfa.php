<?php

namespace App\Support\Mobile;

/**
 * Formatage des montants pour l'interface mobile.
 */
class Fcfa
{
    private const THIN_SPACE = "\u{00A0}";

    /** 1250000 → "1 250 000" (espace insécable). */
    public static function number(float|int|string|null $amount): string
    {
        return number_format((float) $amount, 0, ',', self::THIN_SPACE);
    }

    /** 1250000 → "1 250 000 FCFA". */
    public static function format(float|int|string|null $amount): string
    {
        return self::number($amount)."\u{00A0}FCFA";
    }
}
