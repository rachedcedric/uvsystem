<?php

use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Offres et abonnements.
 *  - purement additive : `tenants.plan` et `tenants.trial_ends_at` NE SONT NI MODIFIÉS NI SUPPRIMÉS
 *    (champs hérités, obsolètes : la source de vérité est désormais tenant_subscriptions) ;
 *  - chaque tenant existant reçoit une ligne d'abonnement (voir backfill) ;
 *  - tables compatibles MySQL, MariaDB et SQLite (aucune syntaxe propre à un moteur).
 * Doit passer APRÈS la migration multi-tenant (table `tenants`) : ordre garanti par l'horodatage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            // Montants en FCFA, entiers : aucun décimal.
            $table->unsignedBigInteger('monthly_price');
            $table->unsignedBigInteger('yearly_price');
            $table->unsignedSmallInteger('trial_days')->default(0);
            // NULL = illimité (convention explicite, jamais 0). Voir SubscriptionEntitlements::isUnlimited().
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_agents')->nullable();
            $table->unsignedBigInteger('storage_limit_mb')->nullable();
            $table->json('features')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('visual_variant', 30)->default('default');
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
        });

        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->string('cycle', 20);
            $table->string('status', 20)->index();
            $table->unsignedBigInteger('applied_price')->default(0); // prix figé à la souscription
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable()->index();
            $table->timestamp('trial_ends_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->string('external_reference')->nullable();
            $table->json('metadata')->nullable();
            // Égal à tenant_id pour l'unique ligne « courante » du tenant, NULL pour l'historique.
            // L'unicité (plusieurs NULL autorisés) garantit à la base qu'un tenant n'a jamais deux
            // abonnements courants, sans index partiel (non portable SQLite/MySQL).
            $table->unsignedBigInteger('current_marker')->nullable()->unique();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('tenant_subscription_id')->constrained('tenant_subscriptions')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('XOF');
            $table->string('status', 20)->index(); // pending | confirmed | failed | cancelled
            $table->string('gateway', 50);
            $table->string('provider_reference')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'provider_reference']);
        });

        Schema::create('subscription_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('tenant_subscription_id')->nullable()->constrained('tenant_subscriptions')->restrictOnDelete();
            $table->string('type', 60);
            // Une notification donnée (type + abonnement + échéance) ne part qu'une fois.
            $table->string('dedup_key')->unique();
            // Reprise : de quoi reconstruire la notification en cas d'échec, et trace persistante de l'échec.
            $table->string('notification')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        foreach (SubscriptionPlanSeeder::definitions() as $definition) {
            DB::table('subscription_plans')->insert([
                ...$definition,
                'features' => json_encode($definition['features']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('tenants')) {
            return;
        }

        $plans = DB::table('subscription_plans')->pluck('id', 'slug');
        $defaultSlug = (string) config('subscriptions.default_legacy_plan', 'entreprise');
        $defaultId = $plans[$defaultSlug] ?? $plans['entreprise'];

        foreach (DB::table('tenants')->orderBy('id')->get() as $tenant) {
            $legacy = $tenant->plan !== null ? Str::slug((string) $tenant->plan) : '';
            $planId = $plans[$legacy] ?? $defaultId;

            $trialEnds = $tenant->trial_ends_at ? \Illuminate\Support\Carbon::parse($tenant->trial_ends_at) : null;
            $status = match (true) {
                $trialEnds === null => 'active',
                $trialEnds->isFuture() => 'trialing',
                default => 'expired',
            };

            DB::table('tenant_subscriptions')->insert([
                'tenant_id' => $tenant->id,
                'subscription_plan_id' => $planId,
                'cycle' => 'monthly',
                'status' => $status,
                // Clients historiques : ils ne paient pas via la plateforme, rien n'est facturé ni compté dans le MRR.
                'applied_price' => 0,
                'starts_at' => $tenant->created_at ?? now(),
                // Pas de date de fin : l'accès d'un tenant existant ne s'arrête pas de lui-même.
                'current_period_ends_at' => null,
                'trial_ends_at' => $trialEnds,
                'auto_renew' => false,
                'metadata' => json_encode(['legacy' => true, 'legacy_plan' => $tenant->plan, 'migrated_at' => now()->toIso8601String()]),
                'current_marker' => $tenant->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Retour arrière : supprime UNIQUEMENT les tables créées ici (l'historique d'abonnements est perdu).
     * `tenants.plan` et `tenants.trial_ends_at` n'ont jamais été touchés.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_notification_logs');
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
