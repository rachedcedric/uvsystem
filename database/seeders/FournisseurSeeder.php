<?php

namespace Database\Seeders;

use App\Models\Fournisseur;
use Illuminate\Database\Seeder;

class FournisseurSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Les données de démonstration sont désormais générées dynamiquement.
     * Pour créer de vrais fournisseurs, utilisez l'interface Filament.
     */
    public function run(): void
    {
        // Vérifier si nous sommes en environnement de développement
        if (! app()->environment('local', 'development')) {
            $this->command->warn('⚠️  FournisseurSeeder est désactivé en production.');
            $this->command->warn('   Utilisez l\'interface Filament pour créer des fournisseurs.');

            return;
        }

        $demoCount = (int) env('DEMO_FOURNISSEURS_COUNT', 5);

        $this->command->info("Création de {$demoCount} fournisseurs de démonstration...");

        // Types de fournisseurs configurables
        $types = ['banque', 'agent_premium'];

        for ($i = 1; $i <= $demoCount; $i++) {
            $type = $types[array_rand($types)];

            $contact = $type === 'banque'
                ? "contact.demo{$i}@".strtolower("banque{$i}").'.sn'
                : '77'.str_pad($i + 1000000, 6, '0', STR_PAD_LEFT);

            $fournisseur = Fournisseur::create([
                'name' => $type === 'banque'
                    ? "Banque Démo #{$i}"
                    : "Agent Premium Démo #{$i}",
                'type' => $type,
                'contact' => $contact,
            ]);

            $this->command->info("✅ Fournisseur créé: {$fournisseur->name} ({$type})");
        }

        $this->command->info("✅ {$demoCount} fournisseurs de démonstration créés avec succès!");
        $this->command->warn('💡 Pour modifier le nombre de fournisseurs, changez DEMO_FOURNISSEURS_COUNT dans .env');
    }
}
