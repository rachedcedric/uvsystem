<?php

namespace App\Services\Reports;

use App\Models\User;

/**
 * Catalogue unique des catégories de « Rapports et exports » : l'écran mobile (/m/rapports), l'écran Web
 * (/master/reports) et les exports PDF / Excel lisent tous cette liste. Ajouter une catégorie ici la rend
 * disponible partout, avec les mêmes droits et les mêmes chiffres.
 */
class ReportCatalog
{
    /** @return array<string, ReportCategory> */
    public function all(): array
    {
        $categories = [
            new Categories\Ventes,
            new Categories\Approvisionnements,
            new Categories\Remboursements,
            new Categories\RemboursementsFournisseurs,
            new Categories\Commissions,
            new Categories\DettesAgents,
            new Categories\DettesFournisseurs,
            new Categories\CreditsDecouverts,
            new Categories\RetoursAgents,
            new Categories\MouvementsCaisse,
            new Categories\Charges,
            new Categories\SituationAgents,
            new Categories\HistoriqueDettes,
        ];

        return collect($categories)->keyBy(fn (ReportCategory $c) => $c->key())->all();
    }

    /** @return array<string, ReportCategory> catégories autorisées pour cet utilisateur */
    public function for(?User $user): array
    {
        return array_filter($this->all(), fn (ReportCategory $c) => $c->allows($user));
    }

    public function get(string $key): ?ReportCategory
    {
        return $this->all()[$key] ?? null;
    }
}
