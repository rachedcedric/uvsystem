<?php

namespace App\Services\Reports\Categories;

use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Services\Reports\OperationCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Retours d'UV des agents (rebalancing) : approvisionnements à crédit sur les comptes « Retour Agent » de chaque
 * agent (+ stock UV du Master, + montant dû à l'agent dans les dettes fournisseurs).
 */
class RetoursAgents extends OperationCategory
{
    public function key(): string
    {
        return 'retours_agents';
    }

    public function label(): string
    {
        return 'Retours agents';
    }

    public function icon(): string
    {
        return 'arrows-right-left';
    }

    public function description(): string
    {
        return 'UV rendus par les agents (rebalancing)';
    }

    protected function permissions(): array
    {
        return ['view_approvisionnements'];
    }

    protected function query(): Builder
    {
        return Approvisionnement::query()->whereHas('fournisseur', fn (Builder $f) => $f->where('type', Fournisseur::TYPE_AGENT)->whereNotNull('agent_id'));
    }

    protected function dateColumn(): string
    {
        return 'date_approvisionnement';
    }

    protected function with(): array
    {
        return ['fournisseur.agent:id,name', 'user:id,name'];
    }

    protected function search(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('reference', 'like', "%{$term}%")
            ->orWhereHas('fournisseur.agent', fn (Builder $a) => $a->where('name', 'like', "%{$term}%")));
    }

    public function totalLabel(): string
    {
        return 'Total retourné';
    }

    public function columns(): array
    {
        return [
            ['label' => 'Date', 'type' => 'datetime'],
            ['label' => 'Référence', 'type' => 'text'],
            ['label' => 'Agent', 'type' => 'text'],
            ['label' => 'Impact', 'type' => 'text'],
            ['label' => 'Saisi par', 'type' => 'text'],
            ['label' => 'Montant (FCFA)', 'type' => 'money'],
        ];
    }

    protected function map(Model $model): array
    {
        $montant = number_format((float) $model->amount, 0, ',', ' ');

        return ['cells' => [
            $model->date_approvisionnement, $model->reference, $model->fournisseur?->agent?->name ?? $model->fournisseur?->name ?? '—',
            "Stock UV +{$montant} · dû à l'agent +{$montant}", $model->user?->name ?? '—', (float) $model->amount,
        ]];
    }

    public function searchPlaceholder(): string
    {
        return 'Agent ou référence';
    }
}
