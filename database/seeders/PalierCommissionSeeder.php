<?php

namespace Database\Seeders;

use App\Models\PalierCommission;
use App\Support\CommissionScale;
use Illuminate\Database\Seeder;

/**
 * PalierCommissionSeeder
 *
 * Insère les paliers de commission en base de données.
 * JAMAIS de valeurs hardcodées dans le code - tout vient de la BDD.
 *
 * Le barème (14 paliers, de 1 000 000 à 74 999 999 FCFA) est défini dans App\Support\CommissionScale : c'est la source unique,
 * partagée avec la mise à jour des masters existants (CommissionScaleUpdater). Les nouveaux masters le reçoivent à la création.
 */
class PalierCommissionSeeder extends Seeder
{
    /**
     * Barème des commissions par volume de transaction
     */
    protected array $paliers = CommissionScale::PALIERS;

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
