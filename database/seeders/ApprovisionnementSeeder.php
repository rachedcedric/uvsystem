<?php

namespace Database\Seeders;

use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\User;
use Illuminate\Database\Seeder;

class ApprovisionnementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        echo "Création d'approvisionnements de démonstration...\n";

        $master = User::first(); // Prendre le premier user
        if (! $master) {
            echo "⚠️  Aucun utilisateur trouvé. Créez d'abord un user avec: php artisan filament:user\n";

            return;
        }

        $fournisseurs = Fournisseur::where('is_active', true)->get();
        if ($fournisseurs->isEmpty()) {
            echo "⚠️  Aucun fournisseur actif trouvé. Exécutez d'abord FournisseurSeeder.\n";

            return;
        }

        $count = env('DEMO_APPROVISIONNEMENTS_COUNT', 10);

        for ($i = 1; $i <= $count; $i++) {
            $fournisseur = $fournisseurs->random();
            // Montants entre 100 000 et 5 000 000 FCFA
            $amount = rand(100000, 5000000);

            $approvisionnement = Approvisionnement::create([
                'user_id' => $master->id,
                'fournisseur_id' => $fournisseur->id,
                'amount' => $amount,
                'date_approvisionnement' => now()->subDays(rand(0, 30)),
                'notes' => 'Approvisionnement de démonstration #'.$i,
            ]);

            echo '✅ Approvisionnement créé: '.number_format($amount, 0, ',', ' ')." FCFA depuis {$fournisseur->name}\n";
        }

        echo "✅ {$count} approvisionnements de démonstration créés avec succès!\n";
        echo "💡 Pour modifier le nombre d'approvisionnements, changez DEMO_APPROVISIONNEMENTS_COUNT dans .env\n";
    }
}
