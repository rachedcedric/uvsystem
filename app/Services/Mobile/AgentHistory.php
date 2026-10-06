<?php

namespace App\Services\Mobile;

use App\Tenancy\TenantContext;
use App\Models\Agent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Historique chronologique d'un agent : ventes / retours (hors annulés) et
 * remboursements, fusionnés. Même requête que AgentDebtDetailPage (écran
 * Filament « Détails de la dette »), avec une limite pour l'affichage mobile.
 */
class AgentHistory
{
    private static function union(Agent $agent): \Illuminate\Database\Query\Builder
    {
        $ventes = TenantContext::table('uv_transactions')
            ->where('agent_id', $agent->id)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->select([
                'transaction_type as type', 'id', 'reference', 'amount',
                DB::raw('NULL as remboursement_amount'),
                'solde_uv_avant', 'solde_uv_apres', 'transaction_date as date_operation',
            ]);

        $remboursements = TenantContext::table('remboursements')
            ->where('agent_id', $agent->id)
            ->whereNull('deleted_at')
            ->select([
                DB::raw("'remboursement' as type"), 'id', 'reference',
                DB::raw('NULL as amount'), 'amount as remboursement_amount',
                DB::raw('NULL as solde_uv_avant'), DB::raw('NULL as solde_uv_apres'),
                'payment_date as date_operation',
            ]);

        return $ventes->unionAll($remboursements);
    }

    private static function ordered(Agent $agent): \Illuminate\Database\Query\Builder
    {
        $union = self::union($agent);

        return DB::table(DB::raw('('.$union->toSql().') as historique'))
            ->mergeBindings($union)
            ->orderByDesc('date_operation')
            ->orderByDesc('id');
    }

    /** @return Collection<int, object> */
    public static function latest(Agent $agent, int $limit): Collection
    {
        return self::ordered($agent)->limit($limit)->get();
    }

    /** @return Collection<int, object> */
    public static function all(Agent $agent): Collection
    {
        return self::ordered($agent)->get();
    }

    public static function count(Agent $agent): int
    {
        $union = self::union($agent);

        return DB::table(DB::raw('('.$union->toSql().') as historique'))->mergeBindings($union)->count();
    }
}
