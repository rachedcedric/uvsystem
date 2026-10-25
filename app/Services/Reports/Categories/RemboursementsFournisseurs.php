<?php

namespace App\Services\Reports\Categories;

use App\Models\PaymentMethod;
use App\Models\RemboursementFournisseur;
use App\Services\Reports\OperationCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Paiements versés aux fournisseurs à crédit. Les paiements annulés restent listés, hors total. */
class RemboursementsFournisseurs extends OperationCategory
{
    private ?array $methods = null;

    public function key(): string
    {
        return 'remboursements_fournisseurs';
    }

    public function label(): string
    {
        return 'Paiements fournisseurs';
    }

    public function icon(): string
    {
        return 'building-library';
    }

    public function description(): string
    {
        return 'Remboursements et avances aux fournisseurs';
    }

    protected function permissions(): array
    {
        return ['view_remboursements_fournisseurs', 'export_remboursements_fournisseurs'];
    }

    protected function query(): Builder
    {
        return RemboursementFournisseur::query();
    }

    protected function dateColumn(): string
    {
        return 'payment_date';
    }

    protected function with(): array
    {
        return ['fournisseur:id,name'];
    }

    protected function search(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('reference', 'like', "%{$term}%")->orWhereHas('fournisseur', fn (Builder $f) => $f->where('name', 'like', "%{$term}%")));
    }

    protected function counted(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    public function columns(): array
    {
        return [
            ['label' => 'Date', 'type' => 'datetime'],
            ['label' => 'Référence', 'type' => 'text'],
            ['label' => 'Fournisseur', 'type' => 'text'],
            ['label' => 'Mode', 'type' => 'text'],
            ['label' => 'Statut', 'type' => 'status'],
            ['label' => 'Montant (FCFA)', 'type' => 'money'],
        ];
    }

    protected function map(Model $model): array
    {
        $this->methods ??= PaymentMethod::pluck('name', 'code')->all();

        return ['cells' => [
            $model->payment_date, $model->reference, $model->fournisseur?->name ?? '—',
            $this->methods[$model->payment_method] ?? (string) $model->payment_method,
            $model->isCancelled() ? 'Annulé' : 'Validé', (float) $model->amount,
        ], 'cancelled' => $model->isCancelled()];
    }

    public function searchPlaceholder(): string
    {
        return 'Fournisseur ou référence';
    }
}
