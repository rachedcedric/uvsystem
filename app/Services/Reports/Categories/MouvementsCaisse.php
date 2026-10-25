<?php

namespace App\Services\Reports\Categories;

use App\Models\CaisseMouvement;
use App\Services\Reports\DateRange;
use App\Services\Reports\OperationCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Mouvements financiers de la caisse (entrées / sorties de liquidité). Les mouvements annulés sont exclus. */
class MouvementsCaisse extends OperationCategory
{
    public function key(): string
    {
        return 'mouvements';
    }

    public function label(): string
    {
        return 'Mouvements financiers';
    }

    public function icon(): string
    {
        return 'wallet';
    }

    public function description(): string
    {
        return 'Entrées et sorties de caisse';
    }

    protected function permissions(): array
    {
        return ['view_caisse'];
    }

    protected function query(): Builder
    {
        return CaisseMouvement::query();
    }

    protected function dateColumn(): string
    {
        return 'date_mouvement';
    }

    protected function with(): array
    {
        return ['categorie:id,name'];
    }

    protected function search(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('libelle', 'like', "%{$term}%")->orWhere('reference', 'like', "%{$term}%"));
    }

    public function totalLabel(): string
    {
        return 'Résultat (entrées − sorties)';
    }

    public function columns(): array
    {
        return [
            ['label' => 'Date', 'type' => 'datetime'],
            ['label' => 'Référence', 'type' => 'text'],
            ['label' => 'Libellé', 'type' => 'text'],
            ['label' => 'Catégorie', 'type' => 'text'],
            ['label' => 'Montant (FCFA)', 'type' => 'signed'],
        ];
    }

    protected function map(Model $model): array
    {
        $signe = $model->type === CaisseMouvement::TYPE_ENTREE ? 1 : -1;

        return ['cells' => [$model->date_mouvement, $model->reference, $model->libelle, $model->categorie?->name ?? 'Non catégorisé', $signe * (float) $model->amount]];
    }

    public function summary(DateRange $range, array $filters = []): array
    {
        $base = $this->filtered($range, $filters);
        $entrees = (float) (clone $base)->where('type', CaisseMouvement::TYPE_ENTREE)->sum('amount');
        $sorties = (float) (clone $base)->where('type', CaisseMouvement::TYPE_SORTIE)->sum('amount');

        return [
            'count' => (clone $base)->count(),
            'total' => $entrees - $sorties,
            'total_label' => $this->totalLabel(),
            'extra' => ['Entrées' => number_format($entrees, 0, ',', ' ').' FCFA', 'Sorties' => number_format($sorties, 0, ',', ' ').' FCFA'],
        ];
    }

    public function searchPlaceholder(): string
    {
        return 'Libellé ou référence';
    }
}
