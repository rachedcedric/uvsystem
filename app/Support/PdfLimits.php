<?php

namespace App\Support;

/**
 * Limites PHP d'une génération de PDF : on relève la mémoire et le temps quand ils sont trop bas, sans jamais
 * abaisser une limite plus haute ou illimitée (CLI, tests, serveur déjà configuré). Abaisser le temps à 120 s
 * dans un processus long (suite de tests, worker) l'interromprait au bout de 120 s de calcul cumulé.
 */
class PdfLimits
{
    private const MEMORY_BYTES = 512 * 1048576;

    private const SECONDS = 120;

    public static function raise(): void
    {
        $memory = self::bytes((string) ini_get('memory_limit'));

        if ($memory !== -1 && $memory < self::MEMORY_BYTES) {
            @ini_set('memory_limit', '512M');
        }

        $seconds = (int) ini_get('max_execution_time');

        if ($seconds > 0 && $seconds < self::SECONDS) {
            @set_time_limit(self::SECONDS);
        }
    }

    /** "512M" → 536870912 ; -1 (ou vide) : illimité. */
    public static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
