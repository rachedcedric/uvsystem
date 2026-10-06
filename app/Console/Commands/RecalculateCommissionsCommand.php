<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Models\UvTransaction;
use Illuminate\Console\Command;

class RecalculateCommissionsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'commissions:recalculate
                            {--agent= : ID de l\'agent spécifique à recalculer}
                            {--dry-run : Afficher ce qui serait fait sans modifier}';

    protected $description = 'Recalculer les commissions de toutes les transactions UV';

    public function handle()
    {
        $this->info('╔════════════════════════════════════════════════════════════════╗');
        $this->info('║         RECALCUL DES COMMISSIONS UV TRANSACTIONS               ║');
        $this->info('╚════════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $dryRun = $this->option('dry-run');
        $agentId = $this->option('agent');

        if ($dryRun) {
            $this->warn('🔍 MODE DRY-RUN: Aucune modification ne sera effectuée');
            $this->newLine();
        }

        // Récupérer les transactions à traiter
        $query = UvTransaction::with('agent')
            ->where('transaction_type', 'distribution'); // Seulement les distributions génèrent des commissions

        if ($agentId) {
            $query->where('agent_id', $agentId);
            $this->info('🎯 Recalcul pour l\'agent ID: '.$agentId);
        } else {
            $this->info('🎯 Recalcul pour TOUTES les transactions');
        }

        $transactions = $query->get();

        if ($transactions->isEmpty()) {
            $this->warn('⚠️  Aucune transaction trouvée');

            return 0;
        }

        $this->info('✅ '.$transactions->count().' transaction(s) trouvée(s)');
        $this->newLine();

        $progressBar = $this->output->createProgressBar($transactions->count());
        $progressBar->start();

        $updated = 0;
        $totalCommissions = 0;
        $errors = [];

        foreach ($transactions as $transaction) {
            try {
                $agent = $transaction->agent;

                if (! $agent) {
                    $errors[] = [
                        'transaction' => $transaction->id,
                        'error' => 'Agent introuvable',
                    ];
                    $progressBar->advance();

                    continue;
                }

                // Calculer la commission
                $commissionRate = $agent->commission_rate ?? 5.00; // Taux par défaut 5%
                $commissionAmount = ($transaction->amount * $commissionRate) / 100;

                if (! $dryRun) {
                    $transaction->update([
                        'commission_rate' => $commissionRate,
                        'commission_amount' => $commissionAmount,
                        'status' => $transaction->status ?? 'completed',
                    ]);
                }

                $updated++;
                $totalCommissions += $commissionAmount;
                $progressBar->advance();

            } catch (\Exception $e) {
                $errors[] = [
                    'transaction' => $transaction->id,
                    'error' => $e->getMessage(),
                ];
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        // Résultats
        $this->info('✅ Recalcul terminé !');
        $this->newLine();

        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['Transactions traitées', $transactions->count()],
                ['Transactions mises à jour', $dryRun ? '(simulation)' : $updated],
                ['Total commissions', number_format($totalCommissions, 0, ',', ' ').' FCFA'],
                ['Erreurs', count($errors)],
            ]
        );

        if (! empty($errors)) {
            $this->newLine();
            $this->error('⚠️  Erreurs rencontrées:');
            $this->table(
                ['Transaction ID', 'Erreur'],
                $errors
            );
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('🔍 Mode DRY-RUN: Aucune modification effectuée');
            $this->info('💡 Pour effectuer le recalcul réel, lancez: php artisan commissions:recalculate');
        } else {
            $this->newLine();
            $this->info('🎯 Commissions recalculées avec succès !');
            $this->info('💡 Rafraîchissez le dashboard pour voir les changements');
        }

        return 0;
    }
}
