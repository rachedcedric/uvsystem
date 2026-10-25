<?php

namespace App\Services\Reports\Categories;

use App\Services\Reports\ClotureHistoryService;
use App\Services\Reports\DateRange;
use App\Services\Reports\ReportCategory;
use App\Services\Reports\ReportPresenter;
use Illuminate\Support\Collection;

/**
 * Historique des dettes et crédits : soldes agents et fournisseurs figés à la clôture de chaque journée (Dette : le
 * Master doit ; Crédit : le compte doit au Master). Une journée sans solde reste listée (« Aucun solde »).
 */
class HistoriqueDettes extends ReportCategory
{
    public function key(): string
    {
        return 'historique_dettes';
    }

    public function label(): string
    {
        return 'Historique des dettes et crédits';
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function description(): string
    {
        return 'Soldes non réglés à la clôture de chaque journée';
    }

    protected function permissions(): array
    {
        return ['view_remboursements', 'view_remboursements_fournisseurs'];
    }

    public function columns(): array
    {
        return [
            ['label' => 'Date', 'type' => 'date'],
            ['label' => 'Type', 'type' => 'text'],
            ['label' => 'Nom', 'type' => 'text'],
            ['label' => 'Nature', 'type' => 'status'],
            ['label' => 'Montant (FCFA)', 'type' => 'money'],
        ];
    }

    /**
     * Filtres de l'utilisateur, restreints à ce qu'il a le droit de voir : agents avec view_remboursements,
     * fournisseurs avec view_remboursements_fournisseurs (mêmes règles que l'écran Dettes).
     */
    public function filters(array $filters): array
    {
        $user = auth()->user();
        if (! $user?->can('view_remboursements')) {
            $filters['type'] = 'fournisseur';
        } elseif (! $user->can('view_remboursements_fournisseurs')) {
            $filters['type'] = 'agent';
        }

        return $filters;
    }

    /** @return Collection<int, array<string, mixed>> journées (plus récente en premier) */
    public function days(DateRange $range, array $filters = []): Collection
    {
        return app(ClotureHistoryService::class)->tous($range, $this->filters($filters));
    }

    public function rows(DateRange $range, array $filters = [], ?int $limit = null): Collection
    {
        $rows = $this->days($range, $filters)->flatMap(fn (array $day) => $day['lignes']->isEmpty()
            ? [['cells' => [$day['date'], '—', $day['resume'], $day['resume'], 0], 'tone' => 'muted']]
            : $day['lignes']->map(fn (array $l) => ['cells' => [$day['date'], $l['type_label'], $l['nom'], $l['nature_label'], $l['montant']], 'tone' => $l['tone']])->all());

        return ($limit ? $rows->take($limit) : $rows)->values();
    }

    public function summary(DateRange $range, array $filters = []): array
    {
        $days = $this->days($range, $filters);
        $latest = $days->first();

        return [
            'count' => $days->sum(fn (array $d) => max(1, $d['lignes']->count())),
            'total' => $latest['total_dettes'] ?? 0.0,
            'total_label' => 'Dettes à la dernière clôture'.($latest ? ' ('.$latest['date']->format('d/m/Y').')' : ''),
            'extra' => [
                'Journées' => (string) $days->count(),
                'Sans solde' => (string) $days->filter(fn (array $d) => $d['lignes']->isEmpty())->count(),
                'Crédits à la dernière clôture' => ReportPresenter::fcfa($latest['total_credits'] ?? 0),
            ],
            // Pas de ligne de total : une même dette figure à chaque journée où elle n'est pas soldée.
            'footer' => null,
        ];
    }

    public function searchPlaceholder(): string
    {
        return 'Nom de l\'agent ou du fournisseur';
    }
}
