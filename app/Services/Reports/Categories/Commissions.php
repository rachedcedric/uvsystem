<?php

namespace App\Services\Reports\Categories;

use App\Models\Commission;
use App\Services\Reports\DateRange;
use App\Services\Reports\OperationCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Commissions journalières globales (même base et même total que l'écran Commissions : lignes globales). */
class Commissions extends OperationCategory
{
    public function key(): string
    {
        return 'commissions';
    }

    public function label(): string
    {
        return 'Commissions';
    }

    public function icon(): string
    {
        return 'currency-dollar';
    }

    public function description(): string
    {
        return 'Commissions journalières calculées';
    }

    protected function permissions(): array
    {
        return ['view_commissions', 'export_commissions'];
    }

    protected function query(): Builder
    {
        return Commission::query()->globales();
    }

    protected function dateColumn(): string
    {
        return 'date_commission';
    }

    protected function amountColumn(): string
    {
        return 'commission_globale';
    }

    protected function search(Builder $query, string $term): Builder
    {
        return $query->where('notes', 'like', "%{$term}%");
    }

    public function columns(): array
    {
        return [
            ['label' => 'Date', 'type' => 'date'],
            ['label' => 'Ventes', 'type' => 'text'],
            ['label' => 'Volume du jour (FCFA)', 'type' => 'money'],
            ['label' => 'Commission (FCFA)', 'type' => 'money'],
        ];
    }

    protected function map(Model $model): array
    {
        return ['cells' => [$model->date_commission, (string) (int) $model->nombre_transactions, (float) $model->volume_total_jour, (float) $model->commission_globale]];
    }

    public function summary(DateRange $range, array $filters = []): array
    {
        $summary = parent::summary($range, $filters);
        $summary['extra'] = ['Volume des ventes' => number_format((float) $this->filtered($range, $filters)->sum('volume_total_jour'), 0, ',', ' ').' FCFA'];

        return $summary;
    }

    public function searchPlaceholder(): string
    {
        return 'Notes';
    }
}
