<?php

namespace App\Filament\Widgets;

use App\Models\Agent;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

class RealTimeActivityWidget extends Widget
{
    protected static string $view = 'filament.widgets.real-time-activity-widget';

    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '15s';

    protected int|string|array $columnSpan = 'full';

    private const PER_PAGE = 10;

    public int $page = 1;

    public function nextPage(): void
    {
        $totalPages = max(1, (int) ceil($this->getTotalCount() / self::PER_PAGE));
        $this->page = min($this->page + 1, $totalPages);
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function gotoPage(int $page): void
    {
        $totalPages = max(1, (int) ceil($this->getTotalCount() / self::PER_PAGE));
        $this->page = min(max(1, $page), $totalPages);
    }

    /**
     * Union SQL (ventes / approvisionnements / remboursements) triée de la plus
     * récente à la plus ancienne, paginée en base (LIMIT/OFFSET), sans jamais
     * charger l'intégralité des transactions côté frontend.
     */
    private function buildUnionQuery(): \Illuminate\Database\Query\Builder
    {
        $ventes = DB::table('uv_transactions')
            ->where('transaction_type', 'distribution')
            ->whereNull('deleted_at')
            ->select([
                DB::raw("'vente' as type"),
                'id',
                'reference',
                'agent_id as tiers_id',
                DB::raw("'agent' as tiers_type"),
                'amount',
                'transaction_date as date_operation',
                'status',
            ]);

        $approvisionnements = DB::table('approvisionnements')
            ->whereNull('deleted_at')
            ->select([
                DB::raw("'approvisionnement' as type"),
                'id',
                'reference',
                'user_id as tiers_id',
                DB::raw("'user' as tiers_type"),
                'amount',
                'date_approvisionnement as date_operation',
                DB::raw("'completed' as status"),
            ]);

        $remboursements = DB::table('remboursements')
            ->whereNull('deleted_at')
            ->select([
                DB::raw("'remboursement' as type"),
                'id',
                'reference',
                'agent_id as tiers_id',
                DB::raw("'agent' as tiers_type"),
                'amount',
                'payment_date as date_operation',
                'status',
            ]);

        return $ventes->unionAll($approvisionnements)->unionAll($remboursements);
    }

    private function getTotalCount(): int
    {
        $union = $this->buildUnionQuery();

        return DB::table(DB::raw('('.$union->toSql().') as activites'))
            ->mergeBindings($union)
            ->count();
    }

    public function getActivities(): array
    {
        $total = $this->getTotalCount();
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        if ($this->page > $totalPages) {
            $this->page = $totalPages;
        }

        $union = $this->buildUnionQuery();

        $rows = DB::table(DB::raw('('.$union->toSql().') as activites'))
            ->mergeBindings($union)
            ->orderByDesc('date_operation')
            ->orderByDesc('id')
            ->limit(self::PER_PAGE)
            ->offset((self::PER_PAGE) * ($this->page - 1))
            ->get();

        $agentIds = $rows->where('tiers_type', 'agent')->pluck('tiers_id')->unique()->filter();
        $userIds = $rows->where('tiers_type', 'user')->pluck('tiers_id')->unique()->filter();

        $agentNames = Agent::whereIn('id', $agentIds)->pluck('name', 'id');
        $userNames = User::whereIn('id', $userIds)->pluck('name', 'id');

        $items = $rows->map(function ($row) use ($agentNames, $userNames) {
            $tiersNom = $row->tiers_type === 'agent'
                ? ($agentNames[$row->tiers_id] ?? 'Agent inconnu')
                : ($userNames[$row->tiers_id] ?? 'Utilisateur inconnu');

            return [
                'type' => $row->type,
                'reference' => $row->reference,
                'tiers' => $tiersNom,
                'amount' => (float) $row->amount,
                'date_operation' => $row->date_operation,
                'status' => $row->status,
            ];
        })->values()->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $this->page,
            'totalPages' => $totalPages,
            'perPage' => self::PER_PAGE,
        ];
    }
}
