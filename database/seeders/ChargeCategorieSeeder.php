<?php

namespace Database\Seeders;

use App\Models\ChargeCategorie;
use Illuminate\Database\Seeder;

/**
 * Catégories de charges d'exploitation initiales. Insertion idempotente
 * (updateOrCreate par code) — d'autres catégories peuvent être ajoutées
 * plus tard via l'admin (ChargeCategorieResource), sans migration.
 */
class ChargeCategorieSeeder extends Seeder
{
    protected array $categories = [
        ['code' => 'loyer', 'name' => 'Loyer'],
        ['code' => 'internet', 'name' => 'Internet'],
        ['code' => 'electricite', 'name' => 'Électricité'],
        ['code' => 'transport', 'name' => 'Transport'],
        ['code' => 'carburant', 'name' => 'Carburant'],
        ['code' => 'salaires', 'name' => 'Salaires'],
        ['code' => 'communication', 'name' => 'Communication'],
        ['code' => 'fournitures', 'name' => 'Fournitures'],
        ['code' => 'maintenance', 'name' => 'Maintenance'],
        ['code' => 'frais_bancaires', 'name' => 'Frais bancaires'],
        ['code' => 'taxes', 'name' => 'Taxes'],
        ['code' => 'impot', 'name' => 'Impôt'],
        ['code' => 'alimentation', 'name' => 'Alimentation'],
        ['code' => 'autres', 'name' => 'Autres'],
    ];

    public function run(): void
    {
        foreach ($this->categories as $index => $categorie) {
            ChargeCategorie::updateOrCreate(
                ['code' => $categorie['code']],
                [
                    'name' => $categorie['name'],
                    'is_active' => true,
                    'sort_order' => $index,
                ]
            );
        }

        $this->command->info('✅ '.count($this->categories).' catégories de charges créées/mises à jour');
    }
}
