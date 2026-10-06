<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * Offres initiales. Idempotent ET non destructif : une offre existante (même slug) n'est jamais
 * réécrite, pour ne pas écraser ce que le super admin a modifié dans /platform.
 * Un maximum à null signifie « illimité » (jamais 0) ; les montants sont en FCFA entiers.
 */
class SubscriptionPlanSeeder extends Seeder
{
    /** @return list<array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'Essentiel', 'slug' => 'essentiel',
                'description' => 'Pour démarrer : suivi des agents, des ventes et des dettes.',
                'monthly_price' => 15000, 'yearly_price' => 150000, 'trial_days' => 14,
                'max_users' => 3, 'max_agents' => 100, 'storage_limit_mb' => 1024,
                'features' => ['statistiques_essentielles', 'exports_pdf', 'exports_excel', 'notifications_dettes'],
                'display_order' => 1, 'visual_variant' => 'default', 'is_active' => true, 'is_featured' => false,
            ],
            [
                'name' => 'Pro', 'slug' => 'pro',
                'description' => 'Pour piloter : statistiques avancées, scoring des agents, imports.',
                'monthly_price' => 30000, 'yearly_price' => 300000, 'trial_days' => 14,
                'max_users' => 10, 'max_agents' => 500, 'storage_limit_mb' => 5120,
                'features' => ['toutes_les_fonctions_essentiel', 'statistiques_avancees', 'scoring_agents', 'import_donnees', 'exports_avances', 'notifications_push'],
                'display_order' => 2, 'visual_variant' => 'accent', 'is_active' => true, 'is_featured' => true,
            ],
            [
                'name' => 'Entreprise', 'slug' => 'entreprise',
                'description' => 'Sans limite, avec sauvegardes dédiées et assistance prioritaire.',
                'monthly_price' => 60000, 'yearly_price' => 600000, 'trial_days' => 14,
                'max_users' => null, 'max_agents' => null, 'storage_limit_mb' => null,
                'features' => ['toutes_les_fonctions_pro', 'rapports_avances', 'sauvegardes_dediees', 'assistance_prioritaire', 'options_personnalisees'],
                'display_order' => 3, 'visual_variant' => 'dark', 'is_active' => true, 'is_featured' => false,
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::definitions() as $definition) {
            SubscriptionPlan::query()->firstOrCreate(['slug' => $definition['slug']], $definition);
        }
    }
}
