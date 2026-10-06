<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Models\Agent;
use App\Models\AgentSolde;
use Illuminate\Console\Command;

class RecalculateAgentSoldes extends Command
{
    use RunsPerTenant;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'soldes:recalculate
                            {--agent= : ID d\'un agent spécifique à recalculer}
                            {--force : Forcer le recalcul sans confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalcule les soldes de tous les agents (ou d\'un agent spécifique) en fonction de leurs transactions et remboursements réels';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔄 Recalcul des soldes agents...');
        $this->newLine();

        // Si un agent spécifique est demandé
        if ($agentId = $this->option('agent')) {
            return $this->recalculateSingleAgent($agentId);
        }

        // Sinon, recalculer tous les agents
        return $this->recalculateAllAgents();
    }

    private function recalculateSingleAgent(int $agentId)
    {
        $agent = Agent::find($agentId);

        if (! $agent) {
            $this->error("❌ Agent #$agentId introuvable.");

            return 1;
        }

        $this->info("Agent: {$agent->name}");

        if (! $this->option('force')) {
            if (! $this->confirm('Voulez-vous recalculer le solde de cet agent ?', true)) {
                $this->warn('⚠️  Opération annulée.');

                return 0;
            }
        }

        $solde = AgentSolde::recalculate($agent);

        $this->newLine();
        $this->info('✅ Solde recalculé avec succès !');
        $this->table(
            ['Champ', 'Valeur'],
            [
                ['Total UV Distribuées', number_format($solde->total_uv_distribues, 0, ',', ' ').' FCFA'],
                ['Total Remboursé', number_format($solde->total_rembourse, 0, ',', ' ').' FCFA'],
                ['Dette Restante', number_format($solde->solde_restant, 0, ',', ' ').' FCFA'],
            ]
        );

        return 0;
    }

    private function recalculateAllAgents()
    {
        $agents = Agent::all();
        $totalAgents = $agents->count();

        if ($totalAgents === 0) {
            $this->warn('⚠️  Aucun agent trouvé dans la base de données.');

            return 0;
        }

        $this->info("📊 $totalAgents agent(s) trouvé(s)");
        $this->newLine();

        if (! $this->option('force')) {
            if (! $this->confirm("Voulez-vous recalculer les soldes de tous les $totalAgents agent(s) ?", true)) {
                $this->warn('⚠️  Opération annulée.');

                return 0;
            }
        }

        $this->newLine();
        $progressBar = $this->output->createProgressBar($totalAgents);
        $progressBar->setFormat('verbose');
        $progressBar->start();

        $stats = [
            'with_debt' => 0,
            'without_debt' => 0,
            'total_debt' => 0,
        ];

        foreach ($agents as $agent) {
            $solde = AgentSolde::recalculate($agent);

            if ($solde->solde_restant > 0) {
                $stats['with_debt']++;
                $stats['total_debt'] += $solde->solde_restant;
            } else {
                $stats['without_debt']++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('✅ Recalcul terminé avec succès !');
        $this->newLine();

        $this->table(
            ['Statistique', 'Valeur'],
            [
                ['Total agents traités', $totalAgents],
                ['Agents avec dette', $stats['with_debt']],
                ['Agents sans dette', $stats['without_debt']],
                ['Dette totale système', number_format($stats['total_debt'], 0, ',', ' ').' FCFA'],
            ]
        );

        return 0;
    }
}
