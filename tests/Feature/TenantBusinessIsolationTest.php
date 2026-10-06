<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Commission;
use App\Models\Fournisseur;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UvTransaction;
use App\Services\AnalyticsService;
use App\Services\CaisseService;
use App\Services\MasterSoldeService;
use App\Services\NotificationService;
use App\Services\UvDistributionService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Database\Seeders\PalierCommissionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Les CALCULS métier (soldes UV, caisse, commissions, analyses, destinataires de notifications) sont
 * indépendants d'un tenant à l'autre : ce que fait la société A ne change jamais un chiffre de B.
 */
class TenantBusinessIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    private User $masterA;

    private User $masterB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        Carbon::setTestNow('2026-10-06 10:00:00');

        $this->a = Tenant::create(['name' => 'Société A']);
        $this->b = Tenant::create(['name' => 'Société B']);
        $this->masterA = $this->master($this->a);
        $this->masterB = $this->master($this->b);

        // Paliers de commission propres à chaque tenant (comme le fait le provisionnement)
        foreach ([$this->a, $this->b] as $tenant) {
            TenantContext::runAs($tenant->id, fn () => $this->seed(PalierCommissionSeeder::class));
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function master(Tenant $tenant): User
    {
        return TenantContext::runAs($tenant->id, function () {
            $user = User::factory()->create();
            $user->assignRole('master');

            return $user;
        });
    }

    private function supply(Tenant $tenant, User $user, int $amount): void
    {
        TenantContext::runAs($tenant->id, function () use ($user, $amount) {
            $fournisseur = Fournisseur::factory()->create(['type' => 'AGENT_PREMIUM', 'is_active' => true]);
            Approvisionnement::create(['user_id' => $user->id, 'fournisseur_id' => $fournisseur->id, 'amount' => $amount, 'date_approvisionnement' => now()]);
        });
    }

    private function agent(Tenant $tenant): Agent
    {
        return TenantContext::runAs($tenant->id, fn () => Agent::factory()->create(['status' => 'active', 'debt_limit' => 5_000_000]));
    }

    private function balance(Tenant $tenant): float
    {
        return TenantContext::runAs($tenant->id, fn () => app(MasterSoldeService::class)->getAvailableBalance());
    }

    // ---- solde UV -----------------------------------------------------------------------------------------------

    public function test_each_tenant_has_its_own_uv_balance_and_a_sale_in_one_never_moves_the_other(): void
    {
        $this->supply($this->a, $this->masterA, 1_000_000);
        $this->supply($this->b, $this->masterB, 5_000_000);
        $agentA = $this->agent($this->a);

        $this->assertSame(1_000_000.0, $this->balance($this->a));
        $this->assertSame(5_000_000.0, $this->balance($this->b));

        $this->actingAs($this->masterA);
        (new UvDistributionService)->distribute($agentA->fresh(), 200_000);

        $this->assertSame(800_000.0, $this->balance($this->a));
        $this->assertSame(5_000_000.0, $this->balance($this->b), 'La vente de A ne doit pas toucher le solde de B.');
    }

    public function test_a_tenant_cannot_spend_the_other_tenants_uv_stock(): void
    {
        $this->supply($this->a, $this->masterA, 100_000);
        $this->supply($this->b, $this->masterB, 9_000_000);
        $agentA = $this->agent($this->a);

        $this->actingAs($this->masterA);

        $this->expectException(ValidationException::class);
        (new UvDistributionService)->distribute($agentA->fresh(), 500_000); // A n'a que 100 000 : le stock de B ne compte pas
    }

    // ---- caisse ---------------------------------------------------------------------------------------------------

    public function test_the_cash_register_balance_is_per_tenant(): void
    {
        $this->actingAs($this->masterA);
        app(CaisseService::class)->enregistrer('entree', 40_000, 'Apport A');

        $this->actingAs($this->masterB);
        app(CaisseService::class)->enregistrer('entree', 90_000, 'Apport B');

        $this->actingAs($this->masterA);
        $this->assertSame(40_000.0, (float) app(CaisseService::class)->soldeActuel());

        $this->actingAs($this->masterB);
        $this->assertSame(90_000.0, (float) app(CaisseService::class)->soldeActuel());
    }

    // ---- commissions (cron, sans utilisateur) --------------------------------------------------------------------

    public function test_the_daily_commission_cron_computes_one_commission_per_tenant_from_its_own_sales(): void
    {
        $this->supply($this->a, $this->masterA, 4_000_000);
        $this->supply($this->b, $this->masterB, 9_000_000);

        foreach ([[$this->a, $this->masterA, 1_000_000], [$this->b, $this->masterB, 2_500_000]] as [$tenant, $master, $volume]) {
            $agent = $this->agent($tenant);
            $this->actingAs($master);
            (new UvDistributionService)->distribute($agent->fresh(), $volume);
        }

        // On quitte tout contexte utilisateur : comme le planificateur (mode « système »).
        $this->app['auth']->forgetGuards();
        TenantContext::forceConsole(true);
        Artisan::call('commissions:calculer', ['--date' => '2026-10-06']);
        TenantContext::forceConsole(null);

        $volumes = [];
        foreach ([$this->a, $this->b] as $tenant) {
            $volumes[$tenant->id] = TenantContext::runAs($tenant->id, fn () => Commission::where('is_global', true)->pluck('volume_total_jour')->map(fn ($v) => (float) $v)->all());
        }

        $this->assertSame([1_000_000.0], $volumes[$this->a->id], 'Commission de A calculée sur les seules ventes de A.');
        $this->assertSame([2_500_000.0], $volumes[$this->b->id], 'Commission de B calculée sur les seules ventes de B.');
    }

    // ---- analyses et cache ---------------------------------------------------------------------------------------

    public function test_analytics_and_their_cache_never_mix_tenants(): void
    {
        $this->supply($this->a, $this->masterA, 4_000_000);
        $this->supply($this->b, $this->masterB, 9_000_000);

        foreach ([[$this->a, $this->masterA, 300_000], [$this->b, $this->masterB, 700_000]] as [$tenant, $master, $volume]) {
            $agent = $this->agent($tenant);
            $this->actingAs($master);
            (new UvDistributionService)->distribute($agent->fresh(), $volume);
        }

        $service = app(AnalyticsService::class);

        $this->actingAs($this->masterA);
        $kpisA = $service->getDashboardKpis('month');
        $this->actingAs($this->masterB);
        $kpisB = $service->getDashboardKpis('month');
        $this->actingAs($this->masterA);
        $again = $service->getDashboardKpis('month'); // servi par le cache : doit rester celui de A

        $this->assertNotEquals($kpisA, $kpisB, 'Deux tenants différents ne peuvent pas avoir les mêmes chiffres en cache.');
        $this->assertEquals($kpisA, $again);
    }

    // ---- destinataires de notifications -------------------------------------------------------------------------

    public function test_debt_reminders_only_target_the_tenants_own_admins(): void
    {
        $adminA = TenantContext::runAs($this->a->id, function () {
            $u = User::factory()->create();
            $u->assignRole('admin');

            return $u;
        });

        $recipients = fn (Tenant $tenant) => TenantContext::runAs($tenant->id, function () {
            $method = new \ReflectionMethod(NotificationService::class, 'getDebtReminderRecipients');

            return $method->invoke(app(NotificationService::class))->pluck('id')->sort()->values()->all();
        });

        $this->assertSame([$this->masterA->id, $adminA->id], $recipients($this->a));
        $this->assertSame([$this->masterB->id], $recipients($this->b));
    }

    // ---- intégrité des transactions -----------------------------------------------------------------------------

    public function test_reference_numbers_never_collide_across_tenants(): void
    {
        $this->supply($this->a, $this->masterA, 3_000_000);
        $this->supply($this->b, $this->masterB, 3_000_000);

        foreach ([[$this->a, $this->masterA], [$this->b, $this->masterB]] as [$tenant, $master]) {
            $agent = $this->agent($tenant);
            $this->actingAs($master);
            foreach (range(1, 5) as $i) {
                (new UvDistributionService)->distribute($agent->fresh(), 10_000);
            }
        }

        $all = UvTransaction::withoutGlobalScopes()->pluck('reference');
        $this->assertSame(10, $all->count());
        $this->assertSame(10, $all->unique()->count(), 'Les références restent uniques sur toute la plateforme.');
    }
}
