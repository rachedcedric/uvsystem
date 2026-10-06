<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Models\Agent;
use Illuminate\Console\Command;

class RecalculateAgentDebtsCommand extends Command
{
    use RunsPerTenant;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agents:recalculate-debts
                            {--agent= : ID spécifique d\'un agent à recalculer}
                            {--dry-run : Afficher les changements sans les appliquer}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculer la dette de tous les agents basée sur les transactions et remboursements';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('');
        $this->info('🔄 Recalcul des dettes agents');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $agentId = $this->option('agent');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('⚠️  Mode DRY-RUN : aucune modification ne sera effectuée');
            $this->info('');
        }

        // Récupérer les agents
        $query = Agent::query();
        if ($agentId) {
            $query->where('id', $agentId);
        }
        $agents = $query->get();

        if ($agents->isEmpty()) {
            $this->error('❌ Aucun agent trouvé.');

            return self::FAILURE;
        }

        $this->info("📊 Agents à traiter: {$agents->count()}");
        $this->info('');

        $changes = [];
        $totalOldDebt = 0;
        $totalNewDebt = 0;

        $headers = ['ID', 'Nom', 'Ancienne Dette', 'Nouvelle Dette', 'Différence'];
        $rows = [];

        foreach ($agents as $agent) {
            $oldDebt = $agent->current_debt;
            $totalOldDebt += $oldDebt;

            // Calculer la nouvelle dette (même formule que Agent::recalculateDebt())
            $totaux = $agent->soldeTotals();
            $newDebt = $totaux['distribue'] - $totaux['retourne'] - $totaux['rembourse'];
            $totalNewDebt += $newDebt;

            $diff = $newDebt - $oldDebt;
            $diffFormatted = $diff >= 0
                ? '+'.number_format($diff, 0, ',', ' ')
                : number_format($diff, 0, ',', ' ');

            $rows[] = [
                $agent->id,
                substr($agent->name, 0, 25),
                number_format($oldDebt, 0, ',', ' ').' F',
                number_format($newDebt, 0, ',', ' ').' F',
                $diff != 0 ? $diffFormatted.' F' : '—',
            ];

            if ($diff != 0) {
                $changes[] = [
                    'agent' => $agent,
                    'old' => $oldDebt,
                    'new' => $newDebt,
                ];
            }

            // Appliquer le changement si ce n'est pas un dry-run
            if (! $dryRun) {
                $agent->current_debt = $newDebt;
                $agent->saveQuietly();
            }
        }

        $this->table($headers, $rows);

        $this->info('');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 Résumé:');
        $this->info('   Total ancienne dette: '.number_format($totalOldDebt, 0, ',', ' ').' FCFA');
        $this->info('   Total nouvelle dette: '.number_format($totalNewDebt, 0, ',', ' ').' FCFA');

        $totalDiff = $totalNewDebt - $totalOldDebt;
        if ($totalDiff != 0) {
            $diffSign = $totalDiff > 0 ? '+' : '';
            $this->info('   Différence totale: '.$diffSign.number_format($totalDiff, 0, ',', ' ').' FCFA');
        }

        $this->info('');

        if (count($changes) > 0) {
            if ($dryRun) {
                $this->warn('⚠️  '.count($changes).' agent(s) avec des dettes à corriger.');
                $this->info('   Exécutez sans --dry-run pour appliquer les corrections.');
            } else {
                $this->info('✅ '.count($changes).' agent(s) mis à jour avec succès.');
            }
        } else {
            $this->info('✅ Toutes les dettes sont déjà correctes.');
        }

        $this->info('');

        return self::SUCCESS;
    }
}
