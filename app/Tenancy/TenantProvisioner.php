<?php

namespace App\Tenancy;

use App\Models\CaisseCategorie;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionAccessPolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Crée un tenant utilisable : données de référence propres à ce tenant (moyens de paiement,
 * catégories de charges/caisse, paliers de commission) et son premier administrateur (rôle master).
 * Tout s'exécute dans le contexte du nouveau tenant : rien ne peut atterrir chez un autre.
 */
class TenantProvisioner
{
    private const CAISSE_CATEGORIES = ['Espèce', 'UV Djamo', 'UV Orange Money', 'UV Push', 'Wave'];

    private const CAISSE_SYSTEM = 'Non catégorisé';

    /**
     * @param  array{name: string, email: string, password: string}  $admin
     * @param  array<string, mixed>  $tenantAttributes
     * @param  (\Closure(Tenant): mixed)|null  $subscribe  attribue l'abonnement du tenant AVANT la création de son premier
     *                                                     compte : un tenant ne naît jamais sans abonnement dans un parcours normal
     */
    public function provision(array $tenantAttributes, array $admin, ?\Closure $subscribe = null): Tenant
    {
        return DB::transaction(function () use ($tenantAttributes, $admin, $subscribe) {
            $tenant = Tenant::create($tenantAttributes);

            if ($subscribe) {
                $subscribe($tenant);
            }

            TenantContext::runAs($tenant->id, function () use ($admin) {
                $this->seedReferenceData();
                // Sans abonnement (parcours technique), le premier master doit tout de même pouvoir être créé.
                SubscriptionAccessPolicy::withoutRestrictions(fn () => $this->createAdmin($admin));
            });

            return $tenant;
        });
    }

    public function seedReferenceData(): void
    {
        foreach (['PaymentMethodSeeder', 'ChargeCategorieSeeder', 'PalierCommissionSeeder'] as $seeder) {
            Artisan::call('db:seed', ['--class' => "Database\\Seeders\\{$seeder}", '--force' => true]);
        }

        foreach (self::CAISSE_CATEGORIES as $i => $name) {
            CaisseCategorie::create(['name' => $name, 'sort_order' => $i + 1, 'is_active' => true, 'is_system' => false]);
        }
        CaisseCategorie::create(['name' => self::CAISSE_SYSTEM, 'sort_order' => 999, 'is_active' => true, 'is_system' => true]);
    }

    /** @param  array{name: string, email: string, password?: string, password_hash?: string}  $admin */
    public function createAdmin(array $admin): User
    {
        $user = User::create([
            'name' => $admin['name'],
            'email' => $admin['email'],
            // Souscription publique : le mot de passe arrive déjà haché (jamais conservé en clair entre les deux étapes).
            'password' => $admin['password_hash'] ?? Hash::make($admin['password']),
        ]);
        $user->assignRole('master');

        return $user;
    }
}
