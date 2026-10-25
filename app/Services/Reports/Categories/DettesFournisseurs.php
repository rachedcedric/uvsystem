<?php

namespace App\Services\Reports\Categories;

use App\Services\Reports\DebtCategory;

class DettesFournisseurs extends DebtCategory
{
    public function key(): string
    {
        return 'dettes_fournisseurs';
    }

    public function label(): string
    {
        return 'Dettes fournisseurs';
    }

    public function icon(): string
    {
        return 'building-storefront';
    }

    public function description(): string
    {
        return 'Ce que le Master doit à ses fournisseurs';
    }

    protected function permissions(): array
    {
        return ['view_remboursements_fournisseurs'];
    }

    protected function scope(): array
    {
        return ['type' => 'fournisseur'];
    }
}
