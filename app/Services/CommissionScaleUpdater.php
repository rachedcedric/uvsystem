<?php

namespace App\Services;

use App\Support\CommissionScale;
use Illuminate\Support\Facades\DB;

/**
 * Applique le barème de commissions (App\Support\CommissionScale) aux masters EXISTANTS.
 *
 * Pour chaque entreprise (table tenants) :
 *  - un palier du barème déjà présent (mêmes seuils) est mis à jour (commission) et réactivé ;
 *  - un palier du barème absent est créé ;
 *  - tout autre palier ACTIF est DÉSACTIVÉ, jamais supprimé : le calcul ne regarde que les paliers actifs, donc plus aucun
 *    chevauchement avec l'ancien barème, et l'historique (ou un palier personnalisé) reste récupérable.
 *
 * Idempotent : un second passage ne change plus rien. Écrit au niveau base de données (sans contexte d'entreprise), donc
 * utilisable depuis une commande (commissions:apply-scale, avec simulation). Chaque entreprise est traitée dans sa propre
 * transaction.
 */
class CommissionScaleUpdater
{
    /**
     * @return array<int, array{name: string, created: int, updated: int, deactivated: int}> compte rendu par entreprise
     */
    public function apply(?int $onlyTenantId = null, bool $dryRun = false): array
    {
        $report = [];

        $tenants = DB::table('tenants')
            ->when($onlyTenantId !== null, fn ($query) => $query->where('id', $onlyTenantId))
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($tenants as $tenant) {
            $report[(int) $tenant->id] = ['name' => (string) $tenant->name]
                + DB::transaction(fn () => $this->applyTo((int) $tenant->id, $dryRun));
        }

        return $report;
    }

    /** @return array{created: int, updated: int, deactivated: int} */
    private function applyTo(int $tenantId, bool $dryRun): array
    {
        $existing = DB::table('paliers_commission')->where('tenant_id', $tenantId)->orderBy('id')->get();

        $byRange = [];
        foreach ($existing as $row) {
            $byRange[(int) round((float) $row->seuil_min).'-'.(int) round((float) $row->seuil_max)] ??= $row;
        }

        $kept = [];
        $created = 0;
        $updated = 0;
        $now = now();

        foreach (CommissionScale::PALIERS as $palier) {
            $row = $byRange[$palier['seuil_min'].'-'.$palier['seuil_max']] ?? null;

            if ($row === null) {
                $created++;

                if (! $dryRun) {
                    DB::table('paliers_commission')->insert([
                        'tenant_id' => $tenantId, 'seuil_min' => $palier['seuil_min'], 'seuil_max' => $palier['seuil_max'],
                        'commission' => $palier['commission'], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }

                continue;
            }

            $kept[] = $row->id;

            if ((int) round((float) $row->commission) !== $palier['commission'] || ! $row->is_active) {
                $updated++;

                if (! $dryRun) {
                    DB::table('paliers_commission')->where('id', $row->id)->update(['commission' => $palier['commission'], 'is_active' => true, 'updated_at' => $now]);
                }
            }
        }

        $stale = $existing->filter(fn ($row) => $row->is_active && ! in_array($row->id, $kept, true))->pluck('id')->all();

        if ($stale !== [] && ! $dryRun) {
            DB::table('paliers_commission')->whereIn('id', $stale)->update(['is_active' => false, 'updated_at' => $now]);
        }

        return ['created' => $created, 'updated' => $updated, 'deactivated' => count($stale)];
    }
}
