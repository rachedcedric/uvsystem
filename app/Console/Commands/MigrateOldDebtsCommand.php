<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsForOneTenant;
use App\Models\Agent;
use App\Models\AgentSolde;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateOldDebtsCommand extends Command
{
    use RunsForOneTenant;

    protected $signature = 'debts:migrate-old-system
                            {--tenant= : Identifiant du tenant (obligatoire)}
                            {--dry-run : Afficher ce qui serait fait sans modifier la base}
                            {--force : Forcer la migration sans confirmation}';

    protected $description = 'Migrer les dettes de l\'ancien système (dettes_agents) vers le nouveau (agent_soldes + uv_transactions)';

    public function handle()
    {
        $this->info('╔════════════════════════════════════════════════════════════════╗');
        $this->info('║     MIGRATION: ANCIEN SYSTÈME → NOUVEAU SYSTÈME               ║');
        $this->info('╚════════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        if ($dryRun) {
            $this->warn('🔍 MODE DRY-RUN: Aucune modification ne sera effectuée');
            $this->newLine();
        }

        // 1. Analyser l'ancien système
        $this->info('🔍 Analyse de l\'ancien système...');
        // Base et jointure bornées au tenant : un partenaire d'un autre tenant ne peut jamais être migré ici.
        $oldDebts = TenantContext::table('dettes_agents')
            ->join('agent_partenaires', 'dettes_agents.agent_partenaire_id', '=', 'agent_partenaires.id')
            ->where('agent_partenaires.tenant_id', TenantContext::requireId())
            ->select(
                'dettes_agents.*',
                'agent_partenaires.nom_entreprise',
                'agent_partenaires.numero_telephone',
                'agent_partenaires.lieu'
            )
            ->get();

        if ($oldDebts->isEmpty()) {
            $this->warn('⚠️  Aucune dette trouvée dans l\'ancien système');

            return 0;
        }

        $this->info('✅ '.$oldDebts->count().' dette(s) trouvée(s)');
        $this->newLine();

        // Afficher le résumé
        $this->table(
            ['ID', 'Nom Entreprise', 'Total Achats', 'Remboursé', 'Dette Restante'],
            $oldDebts->map(fn ($debt) => [
                $debt->id,
                $debt->nom_entreprise,
                number_format($debt->total_achats, 0, ',', ' ').' FCFA',
                number_format($debt->total_rembourse, 0, ',', ' ').' FCFA',
                number_format($debt->dette_restante, 0, ',', ' ').' FCFA',
            ])
        );

        $totalOldDebts = $oldDebts->sum('dette_restante');
        $this->info('💰 Total à migrer: '.number_format($totalOldDebts, 0, ',', ' ').' FCFA');
        $this->newLine();

        // 2. Vérifier le nouveau système
        $this->info('🔍 Vérification du nouveau système...');
        $currentDebts = AgentSolde::sum('solde_restant');
        $this->info('💳 Dettes actuelles (nouveau système): '.number_format($currentDebts, 0, ',', ' ').' FCFA');
        $this->newLine();

        // 3. Confirmation
        if (! $force && ! $dryRun) {
            if (! $this->confirm('Voulez-vous continuer la migration ?', true)) {
                $this->warn('❌ Migration annulée');

                return 0;
            }
            $this->newLine();
        }

        // 4. Migration
        $this->info('🚀 Démarrage de la migration...');
        $this->newLine();

        $migratedCount = 0;
        $errors = [];
        $progressBar = $this->output->createProgressBar($oldDebts->count());
        $progressBar->start();

        foreach ($oldDebts as $oldDebt) {
            try {
                if (! $dryRun) {
                    DB::beginTransaction();
                }

                // Créer ou récupérer l'agent dans le nouveau système
                $agent = $this->getOrCreateAgent($oldDebt, $dryRun);

                if (! $dryRun && $agent) {
                    // Créer une transaction UV pour représenter la dette
                    $transaction = UvTransaction::create([
                        'agent_id' => $agent->id,
                        'transaction_type' => 'distribution',
                        'amount' => $oldDebt->total_achats,
                        'commission_amount' => 0,
                        'transaction_date' => $oldDebt->created_at ?? now(),
                        'notes' => 'Migration depuis ancien système (dettes_agents #'.$oldDebt->id.')',
                        'status' => 'completed',
                    ]);

                    // Si il y avait des remboursements, créer des remboursements
                    if ($oldDebt->total_rembourse > 0) {
                        Remboursement::create([
                            'agent_id' => $agent->id,
                            'amount' => $oldDebt->total_rembourse,
                            'payment_method' => 'unknown',
                            'payment_date' => $oldDebt->updated_at ?? now(),
                            'notes' => 'Migration remboursements depuis ancien système (dettes_agents #'.$oldDebt->id.')',
                        ]);
                    }

                    // Les Observers vont automatiquement mettre à jour AgentSolde
                }

                if (! $dryRun) {
                    DB::commit();
                }

                $migratedCount++;
                $progressBar->advance();

            } catch (\Exception $e) {
                if (! $dryRun) {
                    DB::rollBack();
                }
                $errors[] = [
                    'agent' => $oldDebt->nom_entreprise,
                    'error' => $e->getMessage(),
                ];
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        // 5. Résultats
        $this->info('✅ Migration terminée !');
        $this->newLine();

        $this->table(
            ['Métrique', 'Avant', 'Après'],
            [
                [
                    'Dettes totales',
                    number_format($currentDebts, 0, ',', ' ').' FCFA',
                    $dryRun ? '(simulation)' : number_format(AgentSolde::sum('solde_restant'), 0, ',', ' ').' FCFA',
                ],
                [
                    'Agents migrés',
                    '-',
                    $migratedCount.' / '.$oldDebts->count(),
                ],
            ]
        );

        if (! empty($errors)) {
            $this->newLine();
            $this->error('⚠️  Erreurs rencontrées:');
            $this->table(
                ['Agent', 'Erreur'],
                $errors
            );
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('🔍 Mode DRY-RUN: Aucune modification n\'a été effectuée');
            $this->info('💡 Pour effectuer la migration réelle, lancez: php artisan debts:migrate-old-system');
        } else {
            $this->newLine();
            $this->info('🎯 Prochaines étapes recommandées:');
            $this->info('  1. Vérifier les données migrées sur le dashboard');
            $this->info('  2. Si tout est OK, désactiver les anciens Resources (Agent Partenaire)');
            $this->info('  3. Optionnel: Supprimer les anciennes tables après backup');
        }

        return 0;
    }

    private function getOrCreateAgent($oldDebt, $dryRun = false)
    {
        if ($dryRun) {
            return null;
        }

        // Chercher si un agent existe déjà avec ce nom
        $agent = Agent::where('name', $oldDebt->nom_entreprise)->first();

        if (! $agent) {
            // Créer un nouvel agent
            $agent = Agent::create([
                'name' => $oldDebt->nom_entreprise,
                'email' => strtolower(str_replace(' ', '', $oldDebt->nom_entreprise)).'@migrated.local',
                'phone' => $oldDebt->numero_telephone ?? 'N/A',
                'address' => $oldDebt->lieu ?? 'N/A',
                'status' => 'active',
                'commission_rate' => 5.00, // Taux par défaut
                'debt_limit' => null, // Pas de limite
            ]);

            $this->line('  ✓ Agent créé: '.$agent->name);
        } else {
            $this->line('  ℹ Agent existant utilisé: '.$agent->name);
        }

        return $agent;
    }
}
