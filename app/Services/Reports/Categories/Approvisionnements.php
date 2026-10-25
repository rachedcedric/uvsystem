<?php

namespace App\Services\Reports\Categories;

use App\Models\Approvisionnement;
use App\Services\Reports\OperationCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Approvisionnements extends OperationCategory
{
    public function key(): string
    {
        return 'approvisionnements';
    }

    public function label(): string
    {
        return 'Approvisionnements UV';
    }

    public function icon(): string
    {
        return 'truck';
    }

    public function description(): string
    {
        return 'Achats d\'UV auprès des fournisseurs';
    }

    protected function permissions(): array
    {
        return ['view_approvisionnements'];
    }

    /** Achats seulement : les retours d'UV des agents (comptes fournisseurs d'agent) ont leur propre rapport. */
    protected function query(): Builder
    {
        return Approvisionnement::query()->whereDoesntHave('fournisseur', fn (Builder $f) => $f->whereNotNull('agent_id'));
    }

    protected function dateColumn(): string
    {
        return 'date_approvisionnement';
    }

    protected function with(): array
    {
        return ['fournisseur:id,name,type', 'user:id,name'];
    }

    protected function search(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('reference', 'like', "%{$term}%")->orWhereHas('fournisseur', fn (Builder $f) => $f->where('name', 'like', "%{$term}%")));
    }

    public function columns(): array
    {
        return [
            ['label' => 'Date', 'type' => 'datetime'],
            ['label' => 'Référence', 'type' => 'text'],
            ['label' => 'Fournisseur', 'type' => 'text'],
            ['label' => 'Paiement', 'type' => 'status'],
            ['label' => 'Saisi par', 'type' => 'text'],
            ['label' => 'Montant (FCFA)', 'type' => 'money'],
        ];
    }

    protected function map(Model $model): array
    {
        return ['cells' => [
            $model->date_approvisionnement,
            $model->reference,
            $model->fournisseur?->name ?? '—',
            $model->fournisseur?->isCreditFournisseur() ? 'À crédit' : 'Comptant',
            $model->user?->name ?? '—',
            (float) $model->amount,
        ]];
    }

    public function searchPlaceholder(): string
    {
        return 'Fournisseur ou référence';
    }
}
