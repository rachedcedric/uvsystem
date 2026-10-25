<?php

namespace App\Services\Reports\Categories;

use App\Models\Agent;
use App\Services\Reports\DateRange;
use App\Services\Reports\ReportCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Situation des agents (instantané : statut, plafond, dette actuelle). Même contenu que l'export historique. */
class SituationAgents extends ReportCategory
{
    public function key(): string
    {
        return 'situation_agents';
    }

    public function label(): string
    {
        return 'Situation des agents';
    }

    public function icon(): string
    {
        return 'identification';
    }

    public function description(): string
    {
        return 'Statut, plafond et dette actuelle de chaque agent';
    }

    protected function permissions(): array
    {
        return ['view_agents', 'export_agents'];
    }

    public function dated(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            ['label' => 'Agent', 'type' => 'text'],
            ['label' => 'Téléphone', 'type' => 'text'],
            ['label' => 'Statut', 'type' => 'status'],
            ['label' => 'Plafond (FCFA)', 'type' => 'money'],
            ['label' => 'Dette actuelle (FCFA)', 'type' => 'signed'],
        ];
    }

    private function query(array $filters): Builder
    {
        $term = trim((string) ($filters['q'] ?? ''));

        return Agent::query()->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")));
    }

    public function rows(DateRange $range, array $filters = [], ?int $limit = null): Collection
    {
        $labels = ['active' => 'Actif', 'inactive' => 'Inactif', 'suspended' => 'Suspendu', 'blocked' => 'Bloqué'];

        return $this->query($filters)->orderBy('name')->when($limit, fn (Builder $q) => $q->limit($limit))->get()
            ->map(fn (Agent $a) => ['cells' => [$a->name, $a->phone ?? '—', $labels[$a->status] ?? (string) $a->status, (float) $a->debt_limit, (float) $a->current_debt]]);
    }

    public function summary(DateRange $range, array $filters = []): array
    {
        $query = $this->query($filters);

        return [
            'count' => (clone $query)->count(),
            'total' => (float) (clone $query)->where('current_debt', '>', 0)->sum('current_debt'),
            'total_label' => 'Dettes en cours',
        ];
    }

    public function searchPlaceholder(): string
    {
        return 'Nom ou téléphone';
    }
}
