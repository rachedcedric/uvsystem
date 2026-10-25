<?php

namespace App\Services\Reports\Categories;

use App\Models\Charge;
use App\Services\Reports\OperationCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Charges extends OperationCategory
{
    public function key(): string
    {
        return 'charges';
    }

    public function label(): string
    {
        return 'Charges';
    }

    public function icon(): string
    {
        return 'receipt-percent';
    }

    public function description(): string
    {
        return 'Dépenses de fonctionnement';
    }

    protected function permissions(): array
    {
        return ['view_charges', 'export_charges'];
    }

    protected function query(): Builder
    {
        return Charge::query();
    }

    protected function dateColumn(): string
    {
        return 'date_charge';
    }

    protected function amountColumn(): string
    {
        return 'montant';
    }

    protected function with(): array
    {
        return ['category:id,name'];
    }

    protected function search(Builder $query, string $term): Builder
    {
        return $query->where('libelle', 'like', "%{$term}%");
    }

    public function columns(): array
    {
        return [
            ['label' => 'Date', 'type' => 'date'],
            ['label' => 'Libellé', 'type' => 'text'],
            ['label' => 'Catégorie', 'type' => 'text'],
            ['label' => 'Montant (FCFA)', 'type' => 'money'],
        ];
    }

    protected function map(Model $model): array
    {
        return ['cells' => [$model->date_charge, $model->libelle, $model->category?->name ?? '—', (float) $model->montant]];
    }

    public function searchPlaceholder(): string
    {
        return 'Libellé';
    }
}
