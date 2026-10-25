<?php

namespace App\Services\Reports\Categories;

use App\Models\UvTransaction;
use App\Services\Reports\OperationCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Ventes UV (distributions aux agents). Les ventes annulées restent listées mais ne comptent pas dans le total. */
class Ventes extends OperationCategory
{
    public function key(): string
    {
        return 'ventes';
    }

    public function label(): string
    {
        return 'Ventes UV';
    }

    public function icon(): string
    {
        return 'shopping-cart';
    }

    public function description(): string
    {
        return 'UV vendus aux agents';
    }

    protected function permissions(): array
    {
        return ['view_uv_transactions', 'export_uv_transactions'];
    }

    protected function query(): Builder
    {
        return UvTransaction::query()->where('transaction_type', 'distribution');
    }

    protected function dateColumn(): string
    {
        return 'transaction_date';
    }

    protected function with(): array
    {
        return ['agent:id,name'];
    }

    protected function search(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('reference', 'like', "%{$term}%")->orWhereHas('agent', fn (Builder $a) => $a->where('name', 'like', "%{$term}%")));
    }

    protected function counted(Builder $query): Builder
    {
        return $query->where('status', '!=', 'cancelled');
    }

    public function columns(): array
    {
        return [
            ['label' => 'Date', 'type' => 'datetime'],
            ['label' => 'Référence', 'type' => 'text'],
            ['label' => 'Agent', 'type' => 'text'],
            ['label' => 'Statut', 'type' => 'status'],
            ['label' => 'Montant (FCFA)', 'type' => 'money'],
        ];
    }

    protected function map(Model $model): array
    {
        $cancelled = $model->status === 'cancelled';

        return ['cells' => [$model->transaction_date, $model->reference, $model->agent?->name ?? '—', $cancelled ? 'Annulée' : 'Validée', (float) $model->amount], 'cancelled' => $cancelled];
    }

    public function searchPlaceholder(): string
    {
        return 'Agent ou référence';
    }
}
