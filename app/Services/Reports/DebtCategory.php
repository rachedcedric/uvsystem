<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;

/**
 * Catégorie fondée sur l'historique des dettes et crédits (DebtHistoryService) : une ligne par agent ou fournisseur,
 * avec la dette d'ouverture + nouvelles dettes, les montants payés et le solde signé à la fin de la période.
 */
abstract class DebtCategory extends ReportCategory
{
    /** Filtres imposés par la catégorie (type, statut), fusionnés avec ceux de l'écran. */
    protected function scope(): array
    {
        return [];
    }

    public function columns(): array
    {
        return [
            ['label' => 'Dernière opération', 'type' => 'datetime'],
            ['label' => 'Type', 'type' => 'text'],
            ['label' => 'Nom', 'type' => 'text'],
            ['label' => 'Dette initiale (FCFA)', 'type' => 'money'],
            ['label' => 'Montant payé (FCFA)', 'type' => 'money'],
            ['label' => 'Solde (FCFA)', 'type' => 'signed'],
            ['label' => 'Statut', 'type' => 'status'],
        ];
    }

    /** @return Collection<int, array<string, mixed>> lignes brutes de DebtHistoryService */
    public function history(DateRange $range, array $filters = []): Collection
    {
        return app(DebtHistoryService::class)->rows($range, array_merge($filters, $this->scope()));
    }

    public function rows(DateRange $range, array $filters = [], ?int $limit = null): Collection
    {
        return $this->history($range, $filters)
            ->when($limit, fn (Collection $c) => $c->take($limit))
            ->map(fn (array $r) => [
                'cells' => [$r['date'], $r['type_label'], $r['nom'], $r['dette_initiale'], $r['paye'], $r['solde'], $r['statut']],
                'tone' => $r['tone'],
                'raw' => $r,
            ])->values();
    }

    public function summary(DateRange $range, array $filters = []): array
    {
        $rows = $this->history($range, $filters);
        $money = fn (float $n) => number_format($n, 0, ',', ' ').' FCFA';

        return [
            'count' => $rows->count(),
            'total' => round($rows->sum('solde'), 2),
            'total_label' => 'Solde net',
            'extra' => [
                'Dettes en cours' => $money($rows->where('solde', '>', 0)->sum('solde')),
                'Crédits / découverts' => $money($rows->where('solde', '<', 0)->sum('solde')),
                'Payé sur la période' => $money($rows->sum('paye')),
            ],
            'footer' => [3 => $rows->sum('dette_initiale'), 4 => $rows->sum('paye'), 5 => $rows->sum('solde')],
        ];
    }

    public function searchPlaceholder(): string
    {
        return 'Nom de l\'agent ou du fournisseur';
    }
}
