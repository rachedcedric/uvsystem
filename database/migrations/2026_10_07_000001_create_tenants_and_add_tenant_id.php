<?php

use App\Tenancy\MigrationPreflight;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 1/2 du passage multi-tenant (additive, sans perte de données).
 *
 *  1. crée `tenants` et le tenant n°1 (la société existante) ;
 *  2. ajoute `tenant_id` (nullable) à chaque table métier ;
 *  3. rattache les lignes des tables cœur au tenant n°1 et vérifie qu'il n'en reste aucune orpheline
 *     (les tables héritées « clients* » ne sont JAMAIS attribuées automatiquement : voir MigrationPreflight).
 *
 * L'étape 2/2 (NOT NULL + index uniques par tenant) est une migration séparée, pour pouvoir
 * revenir en arrière indépendamment. Rollback complet : `php artisan migrate:rollback --step=2`.
 */
return new class extends Migration
{
    /** Tables dont chaque ligne appartient à un tenant (tenant_id obligatoire à l'étape 2). */
    public const TENANT_TABLES = [
        'agent_partenaires', 'agent_soldes', 'agents', 'approvisionnements',
        'caisse_categories', 'caisse_mouvements', 'charge_categories', 'charges',
        'commission_recalculations', 'commissions', 'dettes_agents', 'fournisseurs',
        'master_soldes', 'paliers_commission', 'payment_methods', 'push_tokens',
        'remboursements', 'remboursements_agents', 'remboursements_fournisseurs',
        'sous_distributeur_remboursements', 'sous_distributeur_soldes',
        'sous_distributeur_transactions', 'sous_distributeurs', 'uv_transactions', 'ventes_uv',
    ];

    /** Tables où tenant_id reste nullable (super admin de plateforme, logs de plateforme). */
    public const NULLABLE_TENANT_TABLES = ['users', 'activity_log'];

    /** Index de performance (tenant_id, colonne) — uniquement si la colonne existe. */
    private const PERF_INDEXES = [
        'uv_transactions' => ['created_at', 'status'],
        'remboursements' => ['created_at', 'status'],
        'approvisionnements' => ['created_at'],
        'caisse_mouvements' => ['created_at'],
        'charges' => ['created_at'],
        'commissions' => ['created_at'],
        'agents' => ['status'],
        'activity_log' => ['created_at'],
    ];

    public function up(): void
    {
        // Contrôle préalable : lève MigrationBlocked AVANT toute modification si l'appartenance de données
        // existantes est ambiguë (ex. tables clients*) ou si aucune sauvegarde vérifiable n'existe.
        MigrationPreflight::assertCanMigrate();

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 20)->default('active')->index(); // active | suspended
            $table->string('plan', 50)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });

        $tenantId = DB::table('tenants')->insertGetId([
            'name' => env('DEFAULT_TENANT_NAME', 'MODELEC CI'),
            'slug' => 'modelec-ci',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([...self::TENANT_TABLES, ...self::NULLABLE_TENANT_TABLES] as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            });

            // Propriétaire déterminé : la société existante. Aucune ligne n'est supprimée ni déplacée.
            DB::table($name)->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);

            if (DB::table($name)->whereNull('tenant_id')->exists()) {
                throw new RuntimeException("Migration interrompue : des lignes de `{$name}` n'ont pas pu être rattachées à un tenant.");
            }
        }

        foreach (self::PERF_INDEXES as $name => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasTable($name) && Schema::hasColumn($name, $column)) {
                    Schema::table($name, fn (Blueprint $table) => $table->index(['tenant_id', $column], "{$name}_tenant_{$column}_index"));
                }
            }
        }
    }

    public function down(): void
    {
        $tables = array_filter([...self::TENANT_TABLES, ...self::NULLABLE_TENANT_TABLES], fn ($name) => Schema::hasTable($name) && Schema::hasColumn($name, 'tenant_id'));

        // MySQL/MariaDB : un index composite (tenant_id, …) sert d'appui à la clé étrangère, il ne peut
        // pas être supprimé tant que la clé existe (erreur 1553) → clés d'abord, puis index, puis colonne.
        foreach ($tables as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        }

        foreach (self::PERF_INDEXES as $name => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasTable($name) && Schema::hasColumn($name, $column)) {
                    Schema::table($name, fn (Blueprint $table) => $table->dropIndex("{$name}_tenant_{$column}_index"));
                }
            }
        }

        foreach ($tables as $name) {
            // Index d'appui posé par le retour arrière de la migration 2 : SQLite refuse de supprimer une colonne indexée.
            if (Schema::hasIndex($name, "{$name}_tenant_id_index")) {
                Schema::table($name, fn (Blueprint $table) => $table->dropIndex("{$name}_tenant_id_index"));
            }

            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('tenant_id'));
        }

        Schema::dropIfExists('tenants');
    }
};
