<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\AgentSolde;
use Illuminate\Database\Seeder;

class AgentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Les données de démonstration sont désormais générées dynamiquement.
     * Pour créer de vrais agents, utilisez l'interface Filament.
     */
    public function run(): void
    {
        // Vérifier si nous sommes en environnement de développement
        if (! app()->environment('local', 'development')) {
            $this->command->warn('⚠️  AgentSeeder est désactivé en production.');
            $this->command->warn('   Utilisez l\'interface Filament pour créer des agents.');

            return;
        }

        $demoCount = (int) env('DEMO_AGENTS_COUNT', 5);

        $this->command->info("Création de {$demoCount} agents de démonstration...");

        for ($i = 1; $i <= $demoCount; $i++) {
            $status = $i === $demoCount ? 'inactive' : 'active'; // Dernier agent inactif
            $debtLimit = rand(250000, 2000000);

            $agent = Agent::create([
                'name' => "Agent Démo #{$i}",
                'phone' => '77'.str_pad($i, 6, '0', STR_PAD_LEFT),
                'debt_limit' => $debtLimit,
                'status' => $status,
            ]);

            // Initialize solde for each agent
            AgentSolde::create([
                'agent_id' => $agent->id,
                'total_uv_distribues' => 0,
                'total_rembourse' => 0,
                'solde_restant' => 0,
            ]);

            $this->command->info("✅ Agent créé: {$agent->name} (limite: ".number_format($debtLimit).' FCFA)');
        }

        $this->command->info("✅ {$demoCount} agents de démonstration créés avec succès!");
        $this->command->warn("💡 Pour modifier le nombre d'agents, changez DEMO_AGENTS_COUNT dans .env");
    }
}
