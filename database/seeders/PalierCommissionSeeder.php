<?php

namespace Database\Seeders;

use App\Models\PalierCommission;
use Illuminate\Database\Seeder;

/**
 * PalierCommissionSeeder
 *
 * Insère les paliers de commission en base de données.
 * JAMAIS de valeurs hardcodées dans le code - tout vient de la BDD.
 *
 * Barème officiel :
 * - 1 000 000 à 1 499 999 → 6 360 FCFA
 * - 1 500 000 à 1 999 999 → 8 420 FCFA
 * - 2 000 000 à 2 499 999 → 10 200 FCFA
 * - 2 500 000 à 2 999 999 → 11 700 FCFA
 * - 3 000 000 à 3 999 999 → 14 895 FCFA
 * - 4 000 000 à 4 999 999 → 19 150 FCFA
 * - 5 000 000 à 9 999 999 → 31 915 FCFA
 * - 10 000 000 à 19 999 999 → 52 725 FCFA
 * - 20 000 000 à 29 999 999 → 60 125 FCFA
 */
class PalierCommissionSeeder extends Seeder
{
    /**
     * Barème des commissions par volume de transaction
     */
    protected array $paliers = [
        ['seuil_min' => 1000000, 'seuil_max' => 1499999, 'commission' => 6360],
        ['seuil_min' => 1500000, 'seuil_max' => 1999999, 'commission' => 8420],
        ['seuil_min' => 2000000, 'seuil_max' => 2499999, 'commission' => 10200],
        ['seuil_min' => 2500000, 'seuil_max' => 2999999, 'commission' => 11700],
        ['seuil_min' => 3000000, 'seuil_max' => 3999999, 'commission' => 14895],
        ['seuil_min' => 4000000, 'seuil_max' => 4999999, 'commission' => 19150],
        ['seuil_min' => 5000000, 'seuil_max' => 9999999, 'commission' => 31915],
        ['seuil_min' => 10000000, 'seuil_max' => 19999999, 'commission' => 52725],
        ['seuil_min' => 20000000, 'seuil_max' => 29999999, 'commission' => 60125],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('📊 Insertion des paliers de commission...');

        foreach ($this->paliers as $palier) {
            PalierCommission::updateOrCreate(
                [
                    'seuil_min' => $palier['seuil_min'],
                    'seuil_max' => $palier['seuil_max'],
                ],
                [
                    'commission' => $palier['commission'],
                    'is_active' => true,
                ]
            );

            $this->command->line(sprintf(
                '  ✅ %s - %s FCFA → %s FCFA',
                number_format($palier['seuil_min'], 0, ',', ' '),
                number_format($palier['seuil_max'], 0, ',', ' '),
                number_format($palier['commission'], 0, ',', ' ')
            ));
        }

        $this->command->info('');
        $this->command->info('✅ '.count($this->paliers).' paliers de commission insérés/mis à jour.');
    }
}
