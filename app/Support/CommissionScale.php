<?php

namespace App\Support;

/**
 * Barème des commissions fourni à chaque master : volume de ventes de la journée → commission (FCFA).
 *
 * Source UNIQUE de ce barème : elle alimente les nouveaux masters (PalierCommissionSeeder) et la mise à jour des masters
 * existants (CommissionScaleUpdater, commande commissions:apply-scale). Le calcul des commissions, lui, lit toujours
 * les paliers du master en base : un master peut ensuite les ajuster dans son espace.
 *
 * Au-delà du dernier palier, c'est la commission du dernier palier qui s'applique (CommissionService) ; en dessous du premier
 * palier, aucune commission.
 */
final class CommissionScale
{
    /** @var list<array{seuil_min: int, seuil_max: int, commission: int}> */
    public const PALIERS = [
        ['seuil_min' => 1_000_000, 'seuil_max' => 1_499_999, 'commission' => 4_135],
        ['seuil_min' => 1_500_000, 'seuil_max' => 1_999_999, 'commission' => 5_470],
        ['seuil_min' => 2_000_000, 'seuil_max' => 2_499_999, 'commission' => 6_630],
        ['seuil_min' => 2_500_000, 'seuil_max' => 2_999_999, 'commission' => 7_020],
        ['seuil_min' => 3_000_000, 'seuil_max' => 3_999_999, 'commission' => 8_935],
        ['seuil_min' => 4_000_000, 'seuil_max' => 4_999_999, 'commission' => 12_445],
        // Le maximum de ce palier est 7 499 900 (et non 7 499 999) : valeur du barème fourni, reprise telle quelle.
        ['seuil_min' => 5_000_000, 'seuil_max' => 7_499_900, 'commission' => 17_550],
        ['seuil_min' => 7_500_000, 'seuil_max' => 9_999_999, 'commission' => 19_840],
        ['seuil_min' => 10_000_000, 'seuil_max' => 14_999_999, 'commission' => 26_755],
        ['seuil_min' => 15_000_000, 'seuil_max' => 19_999_999, 'commission' => 34_270],
        ['seuil_min' => 20_000_000, 'seuil_max' => 29_999_999, 'commission' => 39_080],
        ['seuil_min' => 30_000_000, 'seuil_max' => 44_999_999, 'commission' => 67_525],
        ['seuil_min' => 45_000_000, 'seuil_max' => 59_999_999, 'commission' => 72_845],
        ['seuil_min' => 60_000_000, 'seuil_max' => 74_999_999, 'commission' => 74_925],
    ];
}
