<?php

namespace App\Services\Mobile;

use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Remboursement;
use App\Models\User;
use App\Models\UvTransaction;
use App\Services\CommissionService;
use App\Services\MasterSoldeService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Données de l'écran d'accueil mobile. Mêmes sources et mêmes règles que les
 * widgets Filament du tableau de bord (MainKpisWidget, CaChartWidget,
 * AlertesWidget, RealTimeActivityWidget, TopAgentsWidget) : seule la
 * présentation change.
 */
class HomeDashboard
{
    /**
     * @return array{solde: float, hier: float, delta: float, pct: float, trend: string}
     */
    public function solde(): array
    {
        $service = app(MasterSoldeService::class);
        $actuel = $service->getAvailableBalance();
        $hier = $service->getAvailableBalance(asOf: Carbon::yesterday()->endOfDay());

        return ['solde' => $actuel, 'hier' => $hier, 'delta' => $actuel - $hier] + $this->variation($actuel, $hier);
    }

    /**
     * Indicateurs du jour comparés à la veille.
     *
     * @return list<array{key:string,label:string,value:float,pct:float,trend:string,good:string,icon:string}>
     */
    public function kpis(): array
    {
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();

        $ventes = fn (Carbon $d): float => (float) UvTransaction::whereDate('transaction_date', $d)
            ->where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->sum('amount');
        $appro = fn (Carbon $d): float => (float) Approvisionnement::whereDate('date_approvisionnement', $d)->sum('amount');
        $rembs = fn (Carbon $d): float => (float) Remboursement::whereDate('payment_date', $d)->sum('amount');
        $commissions = app(CommissionService::class);

        $dette = (float) Agent::active()->withDebt()->sum('current_debt');
        $detteHier = Agent::totalDetteActiveAsOf($yesterday->copy()->endOfDay());

        $rows = [
            ['key' => 'ventes', 'label' => 'Ventes du jour', 'icon' => 'banknotes', 'now' => $ventes($today), 'before' => $ventes($yesterday), 'good' => 'up'],
            ['key' => 'commission', 'label' => 'Commission prévue', 'icon' => 'star', 'now' => $commissions->commissionPourJourneeMetier($today), 'before' => $commissions->commissionPourJourneeMetier($yesterday), 'good' => 'up'],
            ['key' => 'rembs', 'label' => 'Remboursements', 'icon' => 'arrow-uturn-left', 'now' => $rembs($today), 'before' => $rembs($yesterday), 'good' => 'up'],
            ['key' => 'dette', 'label' => 'Dettes en cours', 'icon' => 'exclamation-triangle', 'now' => $dette, 'before' => $detteHier, 'good' => 'down'],
            ['key' => 'appro', 'label' => 'Approvisionnements', 'icon' => 'truck', 'now' => $appro($today), 'before' => $appro($yesterday), 'good' => 'up'],
        ];

        return array_map(fn (array $r): array => [
            'key' => $r['key'],
            'label' => $r['label'],
            'icon' => $r['icon'],
            'value' => (float) $r['now'],
            'good' => $r['good'],
        ] + $this->variation((float) $r['now'], (float) $r['before']), $rows);
    }

    /**
     * CA (ventes) des 7 derniers jours, jours sans vente à 0.
     *
     * @return list<array{date:string,label:string,value:float,today:bool}>
     */
    public function dailyRevenue(int $days = 7): array
    {
        $since = Carbon::today()->subDays($days - 1);

        $rows = UvTransaction::where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->where('transaction_date', '>=', $since)
            ->selectRaw('DATE(transaction_date) as jour, SUM(amount) as total')
            ->groupBy('jour')
            ->pluck('total', 'jour');

        return collect(range($days - 1, 0))->map(function (int $ago) use ($rows): array {
            $date = Carbon::today()->subDays($ago);

            return [
                'date' => $date->toDateString(),
                'label' => mb_substr($date->translatedFormat('D'), 0, 3),
                'value' => (float) ($rows[$date->toDateString()] ?? 0),
                'today' => $ago === 0,
            ];
        })->all();
    }

    /**
     * Agents dont la dette atteint 70 % ou plus de leur plafond.
     *
     * @return Collection<int, array{id:int,name:string,phone:?string,debt:float,limit:float,ratio:float,level:string}>
     */
    public function debtAlerts(int $limit = 5): Collection
    {
        return Agent::query()
            ->where('status', 'active')
            ->where('current_debt', '>', 0)
            ->where('debt_limit', '>', 0)
            ->whereRaw('current_debt * 100 >= debt_limit * 70')
            ->orderByRaw('(current_debt * 1.0 / debt_limit) DESC')
            ->limit($limit)
            ->get()
            ->map(function (Agent $agent): array {
                $ratio = $agent->debt_limit > 0 ? ($agent->current_debt / $agent->debt_limit) * 100 : 0;

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'phone' => $agent->phone,
                    'debt' => (float) $agent->current_debt,
                    'limit' => (float) $agent->debt_limit,
                    'ratio' => $ratio,
                    'level' => $ratio >= 100 ? 'critical' : ($ratio >= 90 ? 'high' : 'watch'),
                ];
            });
    }

    /**
     * Dernières opérations (ventes, approvisionnements, remboursements).
     *
     * @return list<array{type:string,reference:?string,tiers:string,amount:float,date:string,status:?string}>
     */
    public function recentActivity(int $limit = 8): array
    {
        $ventes = TenantContext::table('uv_transactions')
            ->where('transaction_type', 'distribution')
            ->whereNull('deleted_at')
            ->select([
                DB::raw("'vente' as type"), 'id', 'reference',
                'agent_id as tiers_id', DB::raw("'agent' as tiers_type"),
                'amount', 'transaction_date as date_operation', 'status',
            ]);

        $appros = TenantContext::table('approvisionnements')
            ->whereNull('deleted_at')
            ->select([
                DB::raw("'approvisionnement' as type"), 'id', 'reference',
                'user_id as tiers_id', DB::raw("'user' as tiers_type"),
                'amount', 'date_approvisionnement as date_operation', DB::raw("'completed' as status"),
            ]);

        $rembs = TenantContext::table('remboursements')
            ->whereNull('deleted_at')
            ->select([
                DB::raw("'remboursement' as type"), 'id', 'reference',
                'agent_id as tiers_id', DB::raw("'agent' as tiers_type"),
                'amount', 'payment_date as date_operation', 'status',
            ]);

        $union = $ventes->unionAll($appros)->unionAll($rembs);

        $rows = DB::table(DB::raw('('.$union->toSql().') as activites'))
            ->mergeBindings($union)
            ->orderByDesc('date_operation')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $agentNames = Agent::withTrashed()->whereIn('id', $rows->where('tiers_type', 'agent')->pluck('tiers_id')->unique()->filter())->pluck('name', 'id');
        $userNames = User::whereIn('id', $rows->where('tiers_type', 'user')->pluck('tiers_id')->unique()->filter())->pluck('name', 'id');

        return $rows->map(fn ($row): array => [
            'type' => $row->type,
            'reference' => $row->reference,
            'tiers' => $row->tiers_type === 'agent'
                ? ($agentNames[$row->tiers_id] ?? 'Agent inconnu')
                : ($userNames[$row->tiers_id] ?? 'Utilisateur inconnu'),
            'amount' => (float) $row->amount,
            'date' => (string) $row->date_operation,
            'status' => $row->status,
        ])->all();
    }

    /**
     * Meilleurs agents du mois (CA des ventes).
     *
     * @return list<array{name:string,total:float}>
     */
    public function topAgentsOfMonth(int $limit = 5): array
    {
        return UvTransaction::query()
            ->select('agent_id', DB::raw('SUM(amount) as total_ca'))
            ->where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->whereMonth('transaction_date', now()->month)
            ->whereYear('transaction_date', now()->year)
            ->groupBy('agent_id')
            ->orderByDesc('total_ca')
            ->limit($limit)
            ->with('agent')
            ->get()
            ->map(fn ($row): array => ['name' => $row->agent?->name ?? 'Inconnu', 'total' => (float) $row->total_ca])
            ->all();
    }

    /**
     * @return array{pct: float, trend: 'up'|'down'|'stable'}
     */
    private function variation(float $now, float $before): array
    {
        if ($before == 0.0) {
            return $now == 0.0 ? ['pct' => 0.0, 'trend' => 'stable'] : ['pct' => 100.0, 'trend' => 'up'];
        }

        $pct = (($now - $before) / $before) * 100;

        return ['pct' => $pct, 'trend' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'stable')];
    }
}
