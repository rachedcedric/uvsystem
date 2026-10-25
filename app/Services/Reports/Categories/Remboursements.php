<?php

namespace App\Services\Reports\Categories;

use App\Models\PaymentMethod;
use App\Models\Remboursement;
use App\Services\Reports\OperationCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Remboursements encaissés auprès des agents. */
class Remboursements extends OperationCategory
{
    private ?array $methods = null;

    public function key(): string
    {
        return 'remboursements';
    }

    public function label(): string
    {
        return 'Remboursements agents';
    }

    public function icon(): string
    {
        return 'arrow-uturn-left';
    }

    public function description(): string
    {
        return 'Dettes réglées par les agents';
    }

    protected function permissions(): array
    {
        return ['view_remboursements', 'export_remboursements'];
    }

    protected function query(): Builder
    {
        return Remboursement::query();
    }

    protected function dateColumn(): string
    {
        return 'payment_date';
    }

    protected function with(): array
    {
        return ['agent:id,name'];
    }

    protected function search(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('reference', 'like', "%{$term}%")->orWhereHas('agent', fn (Builder $a) => $a->where('name', 'like', "%{$term}%")));
    }

    public function columns(): array
    {
        return [
            ['label' => 'Date', 'type' => 'datetime'],
            ['label' => 'Référence', 'type' => 'text'],
            ['label' => 'Agent', 'type' => 'text'],
            ['label' => 'Mode', 'type' => 'text'],
            ['label' => 'Montant (FCFA)', 'type' => 'money'],
        ];
    }

    protected function map(Model $model): array
    {
        $this->methods ??= PaymentMethod::pluck('name', 'code')->all();

        return ['cells' => [$model->payment_date, $model->reference, $model->agent?->name ?? '—', $this->methods[$model->payment_method] ?? (string) $model->payment_method, (float) $model->amount]];
    }

    public function searchPlaceholder(): string
    {
        return 'Agent ou référence';
    }
}
