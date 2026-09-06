<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Génère les références courtes (8 caractères : préfixe + "-" + 6
 * caractères alphanumériques) des NOUVELLES transactions (Vente UV,
 * Remboursement, Approvisionnement). Les références existantes (ancien
 * format "PREFIXE-"+uniqid) ne sont jamais régénérées ni modifiées : ce
 * générateur n'est appelé qu'à la création d'une nouvelle ligne.
 */
class ShortReferenceGenerator
{
    /**
     * Alphabet lisible : sans 0/O, 1/I/L, pour éviter toute confusion à la
     * lecture ou à la ressaisie manuelle d'une référence.
     */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    protected const MAX_ATTEMPTS = 10;

    protected const CODE_LENGTH = 6;

    /**
     * @param  string  $prefix  Une seule lettre : V (Vente UV), R (Remboursement), A (Approvisionnement).
     * @param  string  $table  Table SQL à interroger pour l'unicité (une requête sur le query builder
     *                         brut inclut nativement les lignes soft-deleted, qui portent aussi une
     *                         colonne "reference" à ne jamais dupliquer).
     */
    public function generate(string $prefix, string $table): string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $prefix.'-'.$this->randomCode();

            if (! DB::table($table)->where('reference', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            "Impossible de générer une référence unique pour le préfixe \"{$prefix}\" après ".self::MAX_ATTEMPTS.' tentatives.'
        );
    }

    protected function randomCode(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }
}
