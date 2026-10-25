<?php

namespace App\Services\Reports\Categories;

use App\Services\Reports\DateRange;
use App\Services\Reports\DebtCategory;
use Illuminate\Support\Collection;

/** Historique complet des dettes et crédits (agents et fournisseurs), filtrable par type et par statut. */
class HistoriqueDettes extends DebtCategory
{
    public function key(): string
    {
        return 'historique_dettes';
    }

    public function label(): string
    {
        return 'Historique des dettes et crédits';
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function description(): string
    {
        return 'Dettes, paiements et soldes agents et fournisseurs';
    }

    protected function permissions(): array
    {
        return ['view_remboursements', 'view_remboursements_fournisseurs'];
    }

    public function history(DateRange $range, array $filters = []): Collection
    {
        $user = auth()->user();
        $type = $filters['type'] ?? 'all';
        // Chaque côté n'est visible qu'avec sa permission (mêmes règles que l'écran Dettes).
        if (! $user?->can('view_remboursements')) {
            $type = 'fournisseur';
        } elseif (! $user->can('view_remboursements_fournisseurs')) {
            $type = 'agent';
        }

        return parent::history($range, ['type' => $type] + $filters);
    }
}
