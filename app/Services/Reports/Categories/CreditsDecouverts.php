<?php

namespace App\Services\Reports\Categories;

use App\Services\Reports\DateRange;
use App\Services\Reports\DebtCategory;
use Illuminate\Support\Collection;

/** Soldes négatifs : découverts fournisseurs (avances du Master) et crédits agents (le Master doit à l'agent). */
class CreditsDecouverts extends DebtCategory
{
    public function key(): string
    {
        return 'credits_decouverts';
    }

    public function label(): string
    {
        return 'Crédits et découverts';
    }

    public function icon(): string
    {
        return 'banknotes';
    }

    public function description(): string
    {
        return 'Avances aux fournisseurs et crédits agents';
    }

    protected function permissions(): array
    {
        return ['view_remboursements_fournisseurs', 'view_remboursements'];
    }

    protected function scope(): array
    {
        return ['statut' => 'credit'];
    }

    public function history(DateRange $range, array $filters = []): Collection
    {
        return (new HistoriqueDettes)->history($range, array_merge($filters, $this->scope()));
    }
}
