<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Services\MasterSoldeService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reconstruit de façon sûre le solde_uv_avant/solde_uv_apres des ventes UV
 * et approvisionnements historiques créés avant l'ajout de ce suivi.
 *
 * Principe : rejouer chronologiquement TOUTES les écritures qui affectent
 * le Solde UV Disponible (approvisionnements, ventes non annulées, retours
 * non annulés), en partant de 0. Avant d'écrire quoi que ce soit, on
 * vérifie que le solde final obtenu par ce rejeu correspond exactement au
 * solde réel actuel (MasterSoldeService::getAvailableBalance()) : si ce
 * n'est pas le cas, la reconstruction n'est pas fiable et rien n'est écrit
 * (aucun faux solde n'est jamais créé) — les lignes concernées restent
 * NULL et s'affichent comme "Non disponible" côté interface.
 */
class BackfillMasterSoldeHistoriqueCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'master-solde:backfill
                            {--force : Recalculer même les lignes qui ont déjà un solde enregistré}
                            {--dry-run : Vérifier et afficher le résultat sans rien écrire en base}';

    protected $description = 'Reconstruit le solde avant/après historique des ventes UV et approvisionnements à partir de la chronologie réelle';

    public function handle(MasterSoldeService $masterSoldeService): int
    {
        $this->info('🔍 Chargement de la chronologie complète (approvisionnements + ventes/retours UV)...');

        $evenements = $this->chargerEvenements();

        if ($evenements->isEmpty()) {
            $this->info('Aucune donnée à traiter.');

            return self::SUCCESS;
        }

        $this->info("📊 {$evenements->count()} événement(s) au total.");

        $running = 0.0;
        $miseAJour = ['uv_transactions' => [], 'approvisionnements' => []];

        foreach ($evenements as $evenement) {
            $avant = $running;
            $running += $evenement->delta;
            $apres = $running;

            $miseAJour[$evenement->table][] = [
                'id' => $evenement->id,
                'solde_uv_avant' => round($avant, 2),
                'solde_uv_apres' => round($apres, 2),
                'deja_renseigne' => $evenement->deja_renseigne,
            ];
        }

        $soldeRejoue = round($running, 2);
        $soldeReel = round($masterSoldeService->getAvailableBalance(), 2);
        $ecart = round($soldeRejoue - $soldeReel, 2);

        $this->newLine();
        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['Solde reconstitué par rejeu chronologique', number_format($soldeRejoue, 0, ',', ' ').' FCFA'],
                ['Solde réel actuel (MasterSoldeService)', number_format($soldeReel, 0, ',', ' ').' FCFA'],
                ['Écart', number_format($ecart, 2, ',', ' ').' FCFA'],
            ]
        );

        if (abs($ecart) > 0.01) {
            $this->error('❌ La reconstruction ne correspond pas au solde réel — rien n\'a été écrit.');
            $this->error('   Les anciennes transactions resteront affichées "Non disponible" plutôt que d\'afficher un solde potentiellement faux.');

            return self::FAILURE;
        }

        $this->info('✅ Le rejeu chronologique correspond exactement au solde réel — reconstruction fiable.');

        $force = (bool) $this->option('force');

        $uvTransactionsAEcrire = collect($miseAJour['uv_transactions'])
            ->when(! $force, fn ($c) => $c->reject(fn ($l) => $l['deja_renseigne']))
            ->values()->all();
        $approvisionnementsAEcrire = collect($miseAJour['approvisionnements'])
            ->when(! $force, fn ($c) => $c->reject(fn ($l) => $l['deja_renseigne']))
            ->values()->all();

        if (empty($uvTransactionsAEcrire) && empty($approvisionnementsAEcrire)) {
            $this->info('Rien à mettre à jour (toutes les lignes ont déjà un solde enregistré — utilisez --force pour tout recalculer).');

            return self::SUCCESS;
        }

        $this->info('📝 '.count($uvTransactionsAEcrire).' vente(s)/retour(s) UV et '.count($approvisionnementsAEcrire).' approvisionnement(s) à mettre à jour.');

        if ($this->option('dry-run')) {
            $this->warn('[DRY-RUN] Aucune écriture effectuée.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($uvTransactionsAEcrire, $approvisionnementsAEcrire) {
            $this->ecrire('uv_transactions', $uvTransactionsAEcrire);
            $this->ecrire('approvisionnements', $approvisionnementsAEcrire);
        });

        $this->info('✅ Backfill terminé avec succès.');

        return self::SUCCESS;
    }

    /**
     * Charge et trie chronologiquement tous les événements affectant le
     * Solde UV Disponible. Les ventes/retours annulés sont conservés dans
     * la chronologie (pour que leur ligne d'historique ait quand même un
     * solde avant/après affichable) mais avec un delta de 0 : ils n'ont
     * jamais réellement affecté le solde.
     */
    private function chargerEvenements(): Collection
    {
        $approvisionnements = TenantContext::table('approvisionnements')
            ->whereNull('deleted_at')
            ->get(['id', 'created_at', 'amount', 'solde_uv_avant'])
            ->map(fn ($a) => (object) [
                'table' => 'approvisionnements',
                'id' => $a->id,
                't' => $a->created_at,
                'delta' => (float) $a->amount,
                'deja_renseigne' => $a->solde_uv_avant !== null,
            ]);

        $transactions = TenantContext::table('uv_transactions')
            ->whereNull('deleted_at')
            ->get(['id', 'created_at', 'amount', 'transaction_type', 'status', 'solde_uv_avant'])
            ->map(function ($t) {
                $delta = 0.0;
                if ($t->status !== 'cancelled') {
                    $delta = $t->transaction_type === 'distribution' ? -1 * (float) $t->amount : (float) $t->amount;
                }

                return (object) [
                    'table' => 'uv_transactions',
                    'id' => $t->id,
                    't' => $t->created_at,
                    'delta' => $delta,
                    'deja_renseigne' => $t->solde_uv_avant !== null,
                ];
            });

        return $approvisionnements->concat($transactions)
            ->sort(function ($a, $b) {
                return [$a->t, $a->table, $a->id] <=> [$b->t, $b->table, $b->id];
            })
            ->values();
    }

    /**
     * Simple UPDATE ligne par ligne (pas upsert()) : toutes ces lignes
     * existent déjà, et upsert() échouerait ici — sur SQLite comme sur
     * MySQL, la clause INSERT sous-jacente d'un upsert doit satisfaire les
     * contraintes NOT NULL de TOUTES les colonnes de la table (agent_id,
     * amount, reference...) avant même de basculer sur la mise à jour.
     */
    private function ecrire(string $table, array $lignes): void
    {
        foreach ($lignes as $l) {
            // Écriture bornée au tenant courant : un id d'un autre tenant ne peut jamais être touché.
            TenantContext::table($table)->where('id', $l['id'])->update([
                'solde_uv_avant' => $l['solde_uv_avant'],
                'solde_uv_apres' => $l['solde_uv_apres'],
            ]);
        }
    }
}
