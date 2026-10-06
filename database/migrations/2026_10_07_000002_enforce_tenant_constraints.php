<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 2/2 : tenant_id devient obligatoire (NOT NULL) sur les tables métier et les
 * contraintes d'unicité deviennent PAR TENANT (deux sociétés peuvent avoir le même
 * numéro d'agent, la même catégorie, le même code de moyen de paiement…).
 *
 * `users.tenant_id` et `activity_log.tenant_id` restent nullables : un super admin de
 * plateforme n'appartient à aucun tenant.
 *
 * Les références (REF-…) et l'e-mail utilisateur restent uniques globalement : l'e-mail sert
 * d'identifiant de connexion, et une référence globale n'expose rien.
 */
return new class extends Migration
{
    /** table => [colonne, nom de l'ancien index unique] */
    private const UNIQUES = [
        'agents' => ['phone', 'agents_phone_unique'],
        'sous_distributeurs' => ['telephone', 'sous_distributeurs_telephone_unique'],
        'payment_methods' => ['code', 'payment_methods_code_unique'],
        'caisse_categories' => ['normalized_name', 'caisse_categories_normalized_name_unique'],
        'charge_categories' => ['code', 'charge_categories_code_unique'],
    ];

    public function up(): void
    {
        foreach (self::UNIQUES as $name => [$column, $oldIndex]) {
            Schema::table($name, function (Blueprint $table) use ($column, $oldIndex, $name) {
                $table->dropUnique($oldIndex);
                $table->unique(['tenant_id', $column], "{$name}_tenant_{$column}_unique");
            });
        }

        foreach ($this->tenantTables() as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tenantTables() as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->change();
            });
        }

        foreach (self::UNIQUES as $name => [$column, $oldIndex]) {
            Schema::table($name, function (Blueprint $table) use ($column, $oldIndex, $name) {
                $table->dropUnique("{$name}_tenant_{$column}_unique");
                // Échoue volontairement s'il existe déjà des doublons entre tenants : rien n'est écrasé.
                $table->unique($column, $oldIndex);
            });
        }
    }

    /** @return list<string> */
    private function tenantTables(): array
    {
        return (require __DIR__.'/2026_10_07_000001_create_tenants_and_add_tenant_id.php')::TENANT_TABLES;
    }
};
