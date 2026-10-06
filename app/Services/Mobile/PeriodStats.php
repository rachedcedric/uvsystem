<?php

namespace App\Services\Mobile;

use App\Models\Approvisionnement;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use App\Services\BusinessPeriodResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les 4 widgets « Aujourd'hui / Cette semaine / Ce mois / Total cumulé » des
 * écrans Ventes, Remboursements et Approvisionnements, et les bornes exactes
 * utilisées par leurs exports : le total d'une carte et celui de l'export de
 * la même période sont donc toujours identiques.
 *
 * Mêmes règles que les widgets de l'interface complète :
 *  - ventes : distributions non annulées, périodes en jour métier (clôture 18h02) ;
 *  - remboursements et approvisionnements : périodes calendaires.
 */
class PeriodStats
{
    public const MODULES = ['ventes', 'remboursements', 'approvisionnements'];

    public const PERIODS = ['today' => 'Aujourd\'hui', 'week' => 'Cette semaine', 'month' => 'Ce mois', 'all' => 'Total cumulé'];

    /** @return array{model: class-string, column: string} */
    private function config(string $module): array
    {
        return match ($module) {
            'ventes' => ['model' => UvTransaction::class, 'column' => 'transaction_date'],
            'remboursements' => ['model' => Remboursement::class, 'column' => 'payment_date'],
            'approvisionnements' => ['model' => Approvisionnement::class, 'column' => 'date_approvisionnement'],
        };
    }

    /**
     * Bornes d'une période (null = sans borne, pour « Total cumulé »).
     *
     * @return array{start: Carbon, end: Carbon}|null
     */
    public function range(string $module, string $period, ?Carbon $now = null): ?array
    {
        if ($period === 'all') {
            return null;
        }

        $now ??= Carbon::now();

        if ($module === 'ventes') {
            $resolver = app(BusinessPeriodResolver::class);
            $r = match ($period) {
                'today' => $resolver->today($now),
                'week' => $resolver->thisWeek($now),
                'month' => $resolver->thisMonth($now),
            };

            return ['start' => $r['start'], 'end' => $r['end']];
        }

        return match ($period) {
            'today' => ['start' => $now->copy()->startOfDay(), 'end' => $now->copy()->endOfDay()],
            'week' => ['start' => $now->copy()->startOfWeek(), 'end' => $now->copy()->endOfWeek()],
            'month' => ['start' => $now->copy()->startOfMonth(), 'end' => $now->copy()->endOfMonth()],
        };
    }

    /** Lignes d'un module pour une période (base commune aux cartes et aux exports). */
    public function query(string $module, string $period, ?Carbon $now = null): Builder
    {
        ['model' => $model, 'column' => $column] = $this->config($module);
        $range = $this->range($module, $period, $now);

        return $model::query()
            ->when($module === 'ventes', fn (Builder $q) => $q->where('transaction_type', 'distribution')->where('status', '!=', 'cancelled'))
            ->when($range, fn (Builder $q) => $q->whereBetween($column, [$range['start'], $range['end']]));
    }

    /**
     * @return array<string, array{label: string, amount: float, count: int}>
     */
    public function summary(string $module, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $out = [];

        foreach (self::PERIODS as $period => $label) {
            $row = $this->query($module, $period, $now)->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as total')->first();
            $out[$period] = ['label' => $label, 'amount' => (float) $row->total, 'count' => (int) $row->n];
        }

        return $out;
    }

    /**
     * Évolution de la semaine en cours par rapport à la précédente, en % (null si pas de base de comparaison).
     * Remboursements et approvisionnements uniquement, comme dans l'interface complète.
     */
    public function weekTrend(string $module, ?Carbon $now = null): ?float
    {
        if ($module === 'ventes') {
            return null;
        }

        $now ??= Carbon::now();
        ['column' => $column] = $this->config($module);
        $model = $this->config($module)['model'];

        $current = (float) $this->query($module, 'week', $now)->sum('amount');
        $previousStart = $now->copy()->subWeek()->startOfWeek();
        $previous = (float) $model::query()
            ->whereBetween($column, [$previousStart, $now->copy()->subWeek()->endOfWeek()])
            ->sum('amount');

        return $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : ($current > 0 ? 100.0 : 0.0);
    }

    /**
     * Total de la veille (périodes calendaires : remboursements et approvisionnements).
     *
     * @return array{amount: float, count: int}
     */
    public function yesterday(string $module, ?Carbon $now = null): array
    {
        ['model' => $model, 'column' => $column] = $this->config($module);
        $day = ($now ?? Carbon::now())->copy()->subDay();

        $row = $model::query()
            ->whereBetween($column, [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as total')
            ->first();

        return ['amount' => (float) $row->total, 'count' => (int) $row->n];
    }

    /**
     * Évolution du mois en cours : un total par jour, du 1er à aujourd'hui, avec un 0 explicite
     * pour les jours sans opération (un simple groupBy les omettrait).
     *
     * @return array<int, array{date: string, label: string, value: float, today: bool}>
     */
    public function monthDaily(string $module, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $column = $this->config($module)['column'];

        $totals = $this->query($module, 'month', $now)
            ->selectRaw("DATE({$column}) as jour, SUM(amount) as total")
            ->groupBy('jour')
            ->pluck('total', 'jour');

        $days = [];
        for ($d = $now->copy()->startOfMonth(); $d->lte($now); $d->addDay()) {
            $key = $d->toDateString();
            $days[] = ['date' => $key, 'label' => (string) $d->day, 'value' => (float) ($totals[$key] ?? 0), 'today' => $d->isSameDay($now)];
        }

        return $days;
    }

    /** Libellé des bornes d'un export, pour l'en-tête des PDF. */
    public function rangeLabels(string $module, string $period, ?Carbon $now = null): array
    {
        $range = $this->range($module, $period, $now);

        if (! $range) {
            return ['Début', ($now ?? Carbon::now())->format('d/m/Y')];
        }

        $format = $module === 'ventes' ? 'd/m/Y H:i' : 'd/m/Y';

        return [$range['start']->format($format), $range['end']->format($format)];
    }
}
