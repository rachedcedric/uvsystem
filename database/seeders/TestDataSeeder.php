<?php

namespace Database\Seeders;

use App\Console\Commands\Concerns\GuardsDemoCommands;
use App\Models\Agent;
use App\Models\Fournisseur;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use Illuminate\Database\Seeder;

class TestDataSeeder extends Seeder
{
    use GuardsDemoCommands;

    /**
     * Générer des données de test réalistes pour le système UV
     */
    public function run(): void
    {
        if ($refusal = $this->demoRefusal()) {
            $this->command->error('Seeder refusé : '.$refusal);

            return;
        }

        $this->command->info('🚀 Génération des données de test...');

        // Vérifier qu'on a des agents
        $agents = Agent::all();
        if ($agents->isEmpty()) {
            $this->command->error('❌ Aucun agent trouvé. Exécutez d\'abord AgentSeeder.');

            return;
        }

        // Vérifier qu'on a des fournisseurs
        $fournisseurs = Fournisseur::all();
        if ($fournisseurs->isEmpty()) {
            $this->command->warn('⚠️ Création d\'un fournisseur par défaut...');
            $fournisseur = Fournisseur::create([
                'name' => 'Fournisseur Principal',
                'phone' => '+22501234567',
                'email' => 'fournisseur@'.config('branding.domain'),
                'address' => 'Abidjan, Côte d\'Ivoire',
                'status' => 'active',
            ]);
            $fournisseurs = collect([$fournisseur]);
        }

        $this->command->info("📦 {$agents->count()} agents, {$fournisseurs->count()} fournisseurs");

        // Générer des transactions sur les 3 derniers mois
        $this->generateTransactions($agents, $fournisseurs);

        // Générer des remboursements
        $this->generateRemboursements($agents);

        // Afficher les stats
        $this->showStats();
    }

    protected function generateTransactions($agents, $fournisseurs): void
    {
        $this->command->info('💳 Génération des transactions UV...');

        $transactionCount = 0;
        $startDate = now()->subMonths(3);

        foreach ($agents as $agent) {
            // Nombre de transactions par agent (entre 5 et 25)
            $nbTransactions = rand(5, 25);

            for ($i = 0; $i < $nbTransactions; $i++) {
                // Date aléatoire dans les 3 derniers mois
                $transactionDate = $startDate->copy()->addDays(rand(0, 90));

                // Moins de transactions le week-end
                if ($transactionDate->isWeekend() && rand(0, 10) < 7) {
                    continue;
                }

                // Quantité UV (entre 50 et 500)
                // Montant aléatoire (5 000 à 50 000 FCFA)
                $amount = rand(5, 50) * 1000;

                UvTransaction::create([
                    'agent_id' => $agent->id,
                    'fournisseur_id' => $fournisseurs->random()->id,
                    'amount' => $amount,
                    'transaction_type' => 'distribution',
                    'reference' => 'TXN-'.strtoupper(uniqid()),
                    'transaction_date' => $transactionDate,
                    'signature' => hash('sha256', $agent->id.$amount.$transactionDate),
                ]);

                $transactionCount++;
            }
        }

        $this->command->info("   ✅ {$transactionCount} transactions créées");
    }

    protected function generateRemboursements($agents): void
    {
        $this->command->info('💰 Génération des remboursements...');

        $remboursementCount = 0;
        $totalRembourse = 0;

        foreach ($agents as $agent) {
            // Calculer le total des transactions de l'agent
            $totalTransactions = $agent->transactions()->sum('amount');

            if ($totalTransactions == 0) {
                continue;
            }

            // Taux de remboursement variable (40% à 95%)
            $tauxRemboursement = rand(40, 95) / 100;

            // Montant total à rembourser
            $montantARembourser = $totalTransactions * $tauxRemboursement;

            // Diviser en plusieurs remboursements
            $nbRemboursements = rand(2, 8);
            $montantRestant = $montantARembourser;

            for ($i = 0; $i < $nbRemboursements && $montantRestant > 0; $i++) {
                // Date aléatoire dans les 3 derniers mois
                $paymentDate = now()->subDays(rand(1, 85));

                // Montant du remboursement
                if ($i == $nbRemboursements - 1) {
                    $montant = $montantRestant;
                } else {
                    $montant = round($montantRestant * rand(15, 40) / 100, -2);
                }

                $montant = max(1000, min($montant, $montantRestant));

                Remboursement::create([
                    'agent_id' => $agent->id,
                    'amount' => $montant,
                    'payment_method' => collect(['cash', 'mobile_money', 'bank_transfer'])->random(),
                    'reference' => 'RMB-'.strtoupper(uniqid()),
                    'payment_date' => $paymentDate,
                    'notes' => "Remboursement #{$remboursementCount}",
                ]);

                $montantRestant -= $montant;
                $totalRembourse += $montant;
                $remboursementCount++;
            }
        }

        $this->command->info("   ✅ {$remboursementCount} remboursements créés");
        $this->command->info('   💵 Total remboursé: '.number_format($totalRembourse, 0, ',', ' ').' FCFA');
    }

    protected function showStats(): void
    {
        $this->command->info('');
        $this->command->info('📊 Statistiques finales:');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $totalTransactions = UvTransaction::sum('amount');
        $totalRemboursements = Remboursement::sum('amount');
        $dette = $totalTransactions - $totalRemboursements;

        $this->command->info('   Transactions: '.UvTransaction::count().' ('.number_format($totalTransactions, 0, ',', ' ').' FCFA)');
        $this->command->info('   Remboursements: '.Remboursement::count().' ('.number_format($totalRemboursements, 0, ',', ' ').' FCFA)');
        $this->command->info('   Dette totale: '.number_format($dette, 0, ',', ' ').' FCFA');

        // Agents avec dette
        $agentsAvecDette = Agent::all()->filter(function ($agent) {
            $txn = $agent->transactions()->sum('amount');
            $rmb = $agent->remboursements()->sum('amount');

            return ($txn - $rmb) > 0;
        })->count();

        $this->command->info("   Agents endettés: {$agentsAvecDette}");
        $this->command->info('');
    }
}
