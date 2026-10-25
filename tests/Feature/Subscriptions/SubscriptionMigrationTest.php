<?php

namespace Tests\Feature\Subscriptions;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration legacy des abonnements. SQLite uniquement (comme MigrationPreflightTest) : le DDL valide
 * implicitement la transaction de test sur MySQL/MariaDB.
 */
class SubscriptionMigrationTest extends SubscriptionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Test de DDL : SQLite uniquement (transaction de test).');
        }

        DB::statement('PRAGMA defer_foreign_keys = ON');
    }

    private function insertTenant(string $name, ?string $plan, ?string $trialEnds = null, string $status = 'active'): int
    {
        return DB::table('tenants')->insertGetId([
            'name' => $name, 'slug' => str($name)->slug()->toString(), 'status' => $status, 'plan' => $plan,
            'trial_ends_at' => $trialEnds, 'created_at' => '2026-01-15 08:00:00', 'updated_at' => now(),
        ]);
    }

    public function test_the_legacy_plan_is_mapped_without_touching_the_legacy_columns(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 6]); // abonnements (2), retrait du type agent, puis les 3 migrations du 2026-10-25
        $this->assertFalse(Schema::hasTable('tenant_subscriptions'));

        $pro = $this->insertTenant('Chez Pro', 'Pro');
        $ess = $this->insertTenant('Chez Essentiel', 'ESSENTIEL');
        $none = $this->insertTenant('Sans offre', null);
        $unknown = $this->insertTenant('Offre inconnue', 'platinum-xl');
        $trial = $this->insertTenant('En essai', 'pro', now()->addDays(5)->toDateTimeString());
        $expired = $this->insertTenant('Essai fini', 'pro', now()->subDays(5)->toDateTimeString());
        $suspended = $this->insertTenant('Suspendu', 'essentiel', null, 'suspended');
        $tenantsBefore = DB::table('tenants')->count();

        Artisan::call('migrate');

        $slugOf = fn (int $tenantId) => DB::table('tenant_subscriptions')
            ->join('subscription_plans', 'subscription_plans.id', '=', 'tenant_subscriptions.subscription_plan_id')
            ->where('tenant_id', $tenantId)->whereNotNull('current_marker')->value('slug');
        $statusOf = fn (int $tenantId) => DB::table('tenant_subscriptions')->where('tenant_id', $tenantId)->value('status');

        $this->assertSame('pro', $slugOf($pro));
        $this->assertSame('essentiel', $slugOf($ess));
        $this->assertSame('entreprise', $slugOf($none), 'offre par défaut configurable : illimitée, rien n\'est bloqué');
        $this->assertSame('entreprise', $slugOf($unknown));
        $this->assertSame('trialing', $statusOf($trial));
        $this->assertSame('expired', $statusOf($expired));
        $this->assertSame('active', $statusOf($suspended), 'la suspension du tenant reste portée par tenants.status');
        $this->assertSame('active', $statusOf($pro));

        // Exactement un abonnement courant par tenant, aucun prix facturé, aucune échéance inventée.
        $this->assertSame($tenantsBefore, DB::table('tenant_subscriptions')->whereNotNull('current_marker')->count());
        $this->assertSame(0, DB::table('tenant_subscriptions')->where('applied_price', '>', 0)->count());
        $this->assertSame(0, DB::table('tenant_subscriptions')->whereNotNull('current_period_ends_at')->count());
        $this->assertTrue(json_decode(DB::table('tenant_subscriptions')->where('tenant_id', $pro)->value('metadata'), true)['legacy']);

        // Colonnes héritées : intactes.
        $this->assertSame('Pro', DB::table('tenants')->where('id', $pro)->value('plan'));
        $this->assertSame('platinum-xl', DB::table('tenants')->where('id', $unknown)->value('plan'));
        $this->assertNull(DB::table('tenants')->where('id', $none)->value('plan'));
        $this->assertSame($tenantsBefore, DB::table('tenants')->count());
    }

    public function test_the_default_legacy_plan_is_configurable(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 6]); // abonnements (2), retrait du type agent, puis les 3 migrations du 2026-10-25
        config(['subscriptions.default_legacy_plan' => 'essentiel']);
        $id = $this->insertTenant('Défaut changé', null);

        Artisan::call('migrate');

        $this->assertSame('essentiel', DB::table('tenant_subscriptions')
            ->join('subscription_plans', 'subscription_plans.id', '=', 'tenant_subscriptions.subscription_plan_id')
            ->where('tenant_id', $id)->value('slug'));
    }

    public function test_rollback_removes_only_the_new_tables_and_keeps_every_tenant_column(): void
    {
        $id = $this->insertTenant('Garde ses colonnes', 'Pro');
        $tenantsBefore = DB::table('tenants')->count();

        Artisan::call('migrate:rollback', ['--step' => 6]); // abonnements (2), retrait du type agent, puis les 3 migrations du 2026-10-25

        foreach (['subscription_plans', 'tenant_subscriptions', 'subscription_payments', 'subscription_notification_logs', 'subscription_change_requests', 'subscription_signups'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "$table doit disparaître au retour arrière");
        }
        $this->assertTrue(Schema::hasColumns('tenants', ['plan', 'trial_ends_at']));
        $this->assertSame('Pro', DB::table('tenants')->where('id', $id)->value('plan'));
        $this->assertSame($tenantsBefore, DB::table('tenants')->count());

        // Et on peut remonter : la migration est rejouable.
        Artisan::call('migrate');
        $this->assertSame(3, DB::table('subscription_plans')->count());
        $this->assertSame($tenantsBefore, DB::table('tenant_subscriptions')->count());
    }

    public function test_the_migration_creates_the_three_plans_with_integer_prices_and_null_for_unlimited(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 6]); // abonnements (2), retrait du type agent, puis les 3 migrations du 2026-10-25
        Artisan::call('migrate');

        $plans = DB::table('subscription_plans')->orderBy('display_order')->get();
        $this->assertSame(['essentiel', 'pro', 'entreprise'], $plans->pluck('slug')->all());
        $this->assertSame([15000, 30000, 60000], $plans->pluck('monthly_price')->map(fn ($v) => (int) $v)->all());
        $this->assertNull($plans->last()->max_users);
        $this->assertNull($plans->last()->max_agents);
    }

    public function test_the_workflow_tables_are_added_to_an_existing_subscription_installation(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 5]); // workflow d'abonnement, retrait du type agent, puis les 3 migrations du 2026-10-25

        $this->assertTrue(Schema::hasTable('tenant_subscriptions'));
        $this->assertFalse(Schema::hasTable('subscription_change_requests'));
        $this->assertFalse(Schema::hasTable('subscription_signups'));

        Artisan::call('migrate');

        $this->assertTrue(Schema::hasTable('subscription_change_requests'));
        $this->assertTrue(Schema::hasTable('subscription_signups'));
    }
}
