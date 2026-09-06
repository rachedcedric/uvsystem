<?php

namespace App\Support;

/**
 * Source unique pour convertir un montant potentiellement formaté pour
 * l'affichage ("1 250 000", "1,250,000", "1250000 FCFA"...) en valeur
 * numérique propre exploitable pour les calculs, la validation et
 * l'enregistrement en base. À utiliser partout où un champ montant
 * (masqué via TextInput::money(), voir AppServiceProvider) est lu — que ce
 * soit à la soumission du formulaire ou dans un calcul réactif en cours de
 * saisie (Get $get('amount')) — pour ne jamais laisser les espaces de
 * formatage fausser un calcul ou une comparaison.
 */
class Money
{
    /**
     * Nettoie une saisie formatée et la convertit en float. Ne renvoie
     * jamais autre chose qu'un nombre : une entrée vide ou invalide donne 0.
     */
    public static function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $clean = static::clean((string) $value);

        return $clean === '' || $clean === '-' ? 0.0 : (float) $clean;
    }

    /**
     * Vérifie qu'une saisie représente bien un montant valide, une fois les
     * séparateurs de formatage retirés (utilisé pour la validation).
     */
    public static function isValid(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_int($value) || is_float($value)) {
            return true;
        }

        $clean = static::clean((string) $value);

        return $clean !== '' && $clean !== '-' && is_numeric($clean);
    }

    /**
     * Retire tout ce qui n'est pas un chiffre, un point décimal ou un signe
     * moins (espaces de formatage, séparateurs ",", suffixe "FCFA", etc.).
     */
    protected static function clean(string $value): string
    {
        return preg_replace('/[^0-9.\-]/', '', $value) ?? '';
    }
}
