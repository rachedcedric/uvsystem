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
        // Une commission globale par JOUR ET PAR TENANT (dedup_key = date de la commission globale, sinon NULL).
        'commissions' => ['dedup_key', 'commissions_dedup_key_unique'],
    ];

    public function up(): void
    {
        foreach (self::UNIQUES as $name => [$column, $oldIndex]) {
            Schema::table($name, function (Blueprint $table) use ($column, $oldIndex, $name) {
                $table->dropUnique($oldIndex);
                $table->unique(['tenant_id', $column], "{$name}_tenant_{$column}_unique");
            });

            // Index d'appui laissé par un retour arrière précédent : devenu redondant avec l'index composite.
            if (Schema::hasIndex($name, "{$name}_tenant_id_index")) {
                Schema::table($name, fn (Blueprint $table) => $table->dropIndex("{$name}_tenant_id_index"));
            }
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
            // MySQL/MariaDB : l'index composite (tenant_id, …) sert d'appui à la clé étrangère tenant_id ;
            // on le retire seulement après avoir posé un index simple, sinon erreur 1553.
            if (! Schema::hasIndex($name, "{$name}_tenant_id_index")) {
                Schema::table($name, fn (Blueprint $table) => $table->index('tenant_id', "{$name}_tenant_id_index"));
            }

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
        $first = require __DIR__.'/2026_10_07_000001_create_tenants_and_add_tenant_id.php';

        return array_values(array_filter(
            $first::TENANT_TABLES,
            fn (string $name) => Schema::hasTable($name) && Schema::hasColumn($name, 'tenant_id')
        ));
    }
};
