<?php

namespace App\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Catégorie fondée sur une table d'opérations datées : filtre de période [début, lendemain de la fin[, recherche,
 * tri du plus récent au plus ancien, total calculé en SQL (hors opérations annulées).
 */
abstract class OperationCategory extends ReportCategory
{
    abstract protected function query(): Builder;

    abstract protected function dateColumn(): string;

    protected function amountColumn(): string
    {
        return 'amount';
    }

    /** Recherche texte (référence, nom…). */
    abstract protected function search(Builder $query, string $term): Builder;

    /** @return array{cells: list<mixed>, cancelled?: bool, tone?: ?string} */
    abstract protected function map(Model $model): array;

    /** Lignes qui comptent dans le total (par défaut : toutes). */
    protected function counted(Builder $query): Builder
    {
        return $query;
    }

    protected function with(): array
    {
        return [];
    }

    public function totalLabel(): string
    {
        return 'Montant total';
    }

    protected function filtered(DateRange $range, array $filters): Builder
    {
        $term = trim((string) ($filters['q'] ?? ''));
        $column = $this->query()->qualifyColumn($this->dateColumn());

        return $this->query()
            ->where($column, '>=', $range->start())
            ->where($column, '<', $range->endExclusive())
            ->when($term !== '', fn (Builder $q) => $this->search($q, $term));
    }

    public function rows(DateRange $range, array $filters = [], ?int $limit = null): Collection
    {
        return $this->filtered($range, $filters)->with($this->with())
            ->orderByDesc($this->dateColumn())->orderByDesc($this->query()->getModel()->getQualifiedKeyName())
            ->when($limit, fn (Builder $q) => $q->limit($limit))
            ->get()
            ->map(fn (Model $m) => $this->map($m));
    }

    public function summary(DateRange $range, array $filters = []): array
    {
        $base = $this->filtered($range, $filters);

        return [
            'count' => (clone $base)->count(),
            'total' => (float) $this->counted(clone $base)->sum($this->amountColumn()),
            'total_label' => $this->totalLabel(),
        ];
    }
}
