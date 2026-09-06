<?php

namespace App\Filament\Widgets;

use App\Models\UvTransaction;
use App\Services\BusinessPeriodResolver;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * "🏆 Top 5 des Agents (Aujourd'hui)", affiché sous l'historique des Ventes
 * UV. Recalculé à chaque rendu (pas de cache statique) et respecte le jour
 * métier (cutoff 18h02) via BusinessPeriodResolver, comme toutes les autres
 * métriques "aujourd'hui" du projet.
 */
class Top5AgentsTodayWidget extends Widget
{
    protected static string $view = 'filament.widgets.top5-agents-today-widget';

    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return Collection<int, array{name: string, amount: float}>
     */
    public function getTopAgents(): Collection
    {
        $today = app(BusinessPeriodResolver::class)->today();

        return UvTransaction::query()
            ->selectRaw('agent_id, SUM(amount) as total')
            ->where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('transaction_date', [$today['start'], $today['end']])
            ->groupBy('agent_id')
            ->orderByDesc('total')
            ->limit(5)
            ->with('agent')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->agent?->name ?? 'Agent inconnu',
                'amount' => (float) $row->total,
            ]);
    }
}
