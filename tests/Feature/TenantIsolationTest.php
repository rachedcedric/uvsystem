<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\Fournisseur;
use App\Models\PaymentMethod;
use App\Models\Remboursement;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissing;
use App\Tenancy\TenantProvisioner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/**
 * Règle absolue : un utilisateur du tenant A ne voit, ne modifie, ne supprime ni ne crée rien
 * dans le tenant B — quelles que soient les valeurs envoyées par le client.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    private User $userA;

    private User $userB;

    private Agent $agentA;

    private Agent $agentB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // Ici on teste l'isolation : plus de tenant de repli pour les requêtes anonymes.
        TenantContext::setFallback(null);

        $this->a = Tenant::create(['name' => 'Société A']);
        $this->b = Tenant::create(['name' => 'Société B']);

        $this->userA = $this->userIn($this->a);
        $this->userB = $this->userIn($this->b);
        $this->agentA = TenantContext::runAs($this->a->id, fn () => Agent::factory()->create(['name' => 'Agent de A']));
        $this->agentB = TenantContext::runAs($this->b->id, fn () => Agent::factory()->create(['name' => 'Agent de B']));
    }

    private function userIn(Tenant $tenant, string $role = 'master'): User
    {
        return TenantContext::runAs($tenant->id, function () use ($role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            return $user;
        });
    }

    private function superAdmin(): User
    {
        $user = TenantContext::runAs(null, fn () => User::factory()->create());
        $user->assignRole(User::ROLE_SUPER_ADMIN);

        return $user;
    }

    private function remboursementIn(Tenant $tenant, Agent $agent): Remboursement
    {
        return TenantContext::runAs($tenant->id, fn () => Remboursement::forceCreate([
            'agent_id' => $agent->id, 'amount' => 1000, 'payment_method' => 'cash',
            'reference' => 'REF-'.uniqid(), 'payment_date' => now(), 'status' => 'completed',
        ]));
    }

    // ---- lecture ------------------------------------------------------------------------------

    public function test_a_sees_only_its_own_data_and_b_only_its_own(): void
    {
        Sanctum::actingAs($this->userA);
        $ids = collect($this->getJson('/api/agents')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$this->agentA->id], $ids);

        Sanctum::actingAs($this->userB);
        $ids = collect($this->getJson('/api/agents')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$this->agentB->id], $ids);
    }

    public function test_changing_the_id_in_the_url_never_reaches_the_other_tenant(): void
    {
        Sanctum::actingAs($this->userA);

        $this->getJson("/api/agents/{$this->agentA->id}")->assertOk();
        $this->getJson("/api/agents/{$this->agentB->id}")->assertNotFound();
        $this->getJson("/api/agents/{$this->agentB->id}/situation")->assertNotFound();
        $this->getJson("/api/agents/{$this->agentB->id}/transactions")->assertNotFound();
        $this->getJson("/api/agents/{$this->agentB->id}/remboursements")->assertNotFound();
    }

    public function test_dashboard_and_reports_aggregate_only_the_current_tenant(): void
    {
        TenantContext::runAs($this->b->id, fn () => Agent::factory()->count(5)->create(['status' => 'active']));
        Sanctum::actingAs($this->userA);

        $total = $this->getJson('/api/dashboard/realtime')->assertOk()->json('data.agents.total');
        $this->assertSame(1, (int) $total, 'Le tableau de bord de A ne doit compter que ses agents.');
    }

    public function test_query_builder_helper_is_tenant_scoped(): void
    {
        $this->actingAs($this->userA);

        $ids = TenantContext::table('agents')->pluck('id')->all();

        $this->assertSame([$this->agentA->id], $ids);
    }

    // ---- écriture / suppression --------------------------------------------------------------

    public function test_a_cannot_update_or_delete_a_resource_of_b(): void
    {
        Sanctum::actingAs($this->userA);

        $this->putJson("/api/agents/{$this->agentB->id}", ['name' => 'Piraté'])->assertNotFound();
        $this->deleteJson("/api/agents/{$this->agentB->id}")->assertNotFound();

        $this->assertSame('Agent de B', Agent::withoutGlobalScopes()->find($this->agentB->id)->name);
        $this->assertNull(Agent::withoutGlobalScopes()->find($this->agentB->id)->deleted_at);
    }

    public function test_a_cannot_reference_an_agent_of_b_when_creating_a_transaction(): void
    {
        Sanctum::actingAs($this->userA);

        $this->postJson('/api/transactions', ['agent_id' => $this->agentB->id, 'amount' => 1000])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('uv_transactions')->count());
    }

    public function test_creation_ignores_a_tenant_id_sent_by_the_client(): void
    {
        Sanctum::actingAs($this->userA);

        $id = $this->postJson('/api/agents', [
            'name' => 'Nouvel agent', 'phone' => '0700000001',
            'tenant_id' => $this->b->id, 'user_id' => $this->userB->id,
        ])->assertCreated()->json('data.id');

        $this->assertSame($this->a->id, (int) Agent::withoutGlobalScopes()->find($id)->tenant_id);
    }

    public function test_the_server_refuses_a_cross_tenant_write_even_from_trusted_code(): void
    {
        $this->expectException(LogicException::class);

        TenantContext::runAs($this->a->id, fn () => Agent::forceCreate([
            'name' => 'X', 'phone' => '0700000002', 'tenant_id' => $this->b->id,
        ]));
    }

    public function test_tenant_id_is_immutable(): void
    {
        $this->expectException(LogicException::class);

        TenantContext::runAs($this->a->id, function () {
            $agent = Agent::find($this->agentA->id);
            $agent->forceFill(['tenant_id' => $this->b->id])->save();
        });
    }

    public function test_without_events_cannot_bypass_the_tenant_assignment(): void
    {
        $agent = TenantContext::runAs($this->a->id, fn () => Agent::withoutEvents(
            fn () => Agent::forceCreate(['name' => 'Silencieux', 'phone' => '0700000003'])
        ));

        $this->assertSame($this->a->id, (int) $agent->tenant_id);
    }

    // ---- fail closed ------------------------------------------------------------------------

    public function test_without_a_tenant_nothing_is_readable_and_nothing_is_writable(): void
    {
        $this->assertSame(0, Agent::count());
        $this->assertSame(0, TenantContext::table('agents')->count());

        $this->expectException(TenantContextMissing::class);
        Agent::forceCreate(['name' => 'Orphelin', 'phone' => '0700000004']);
    }

    public function test_a_super_admin_sees_no_tenant_business_data(): void
    {
        $this->actingAs($this->superAdmin());

        $this->assertSame(0, Agent::count());
        $this->assertSame(0, Fournisseur::count());
    }

    // ---- fichiers, reçus, exports, journal -------------------------------------------------

    public function test_receipts_of_another_tenant_are_not_reachable(): void
    {
        $remboursementB = $this->remboursementIn($this->b, $this->agentB);
        $remboursementA = $this->remboursementIn($this->a, $this->agentA);

        $this->actingAs($this->userA)
            ->get("/remboursement/{$remboursementB->id}/receipt")->assertNotFound();
        $this->actingAs($this->userA)
            ->get("/remboursement/{$remboursementB->id}/receipt/download")->assertNotFound();
        $this->actingAs($this->userA)
            ->get("/remboursement/{$remboursementA->id}/receipt")->assertOk();
    }

    public function test_activity_log_is_isolated_per_tenant(): void
    {
        TenantContext::runAs($this->a->id, fn () => activity()->log('action de A'));
        TenantContext::runAs($this->b->id, fn () => activity()->log('action de B'));
        TenantContext::runAs(null, fn () => activity()->log('action de plateforme'));

        Sanctum::actingAs($this->userA);
        $descriptions = collect($this->getJson('/api/activities')->assertOk()->json('data'))->pluck('description');

        $this->assertTrue($descriptions->contains('action de A'));
        $this->assertFalse($descriptions->contains('action de B'));
        $this->assertFalse($descriptions->contains('action de plateforme'));
        $this->assertSame(1, Activity::count());
    }

    public function test_exports_are_stored_per_tenant_and_not_downloadable_by_others(): void
    {
        \Storage::fake('local');
        \Storage::disk('local')->put($this->b->storagePath('exports/b.xlsx'), 'secret de B');

        $this->actingAs($this->userA)
            ->get('/exports/download/b.xlsx')->assertNotFound();
        $this->actingAs($this->userB)
            ->get('/exports/download/b.xlsx')->assertOk();
    }

    // ---- utilisateurs, rôles, unicité -------------------------------------------------------

    public function test_users_are_isolated_and_super_admin_role_cannot_be_granted_by_a_tenant(): void
    {
        $this->actingAs($this->userA);

        $this->assertNull(User::find($this->userB->id));
        $this->assertSame([$this->userA->id], User::pluck('id')->all());
        $this->assertNotContains(User::ROLE_SUPER_ADMIN, User::assignableRoleNames());

        // Même si le rôle est attribué par erreur, un compte rattaché à un tenant n'est pas super admin.
        $this->userA->assignRole(User::ROLE_SUPER_ADMIN);
        $this->assertFalse($this->userA->fresh()->isSuperAdmin());
    }

    public function test_uniqueness_is_per_tenant(): void
    {
        TenantContext::runAs($this->a->id, fn () => Agent::forceCreate(['name' => 'A1', 'phone' => '0799999999']));
        TenantContext::runAs($this->b->id, fn () => Agent::forceCreate(['name' => 'B1', 'phone' => '0799999999']));

        $this->assertSame(2, Agent::withoutGlobalScopes()->where('phone', '0799999999')->count());

        $this->expectException(\Illuminate\Database\QueryException::class);
        TenantContext::runAs($this->a->id, fn () => Agent::forceCreate(['name' => 'A2', 'phone' => '0799999999']));
    }

    // ---- suspension et super admin -----------------------------------------------------------

    public function test_a_suspended_tenant_is_locked_out_everywhere(): void
    {
        $this->a->suspend();

        Sanctum::actingAs($this->userA->fresh());
        $this->getJson('/api/agents')->assertForbidden();

        $this->actingAs($this->userA->fresh())->get('/m')->assertForbidden();

        $this->postJson('/api/auth/login', ['email' => $this->userA->email, 'password' => 'password'])
            ->assertStatus(422);

        $this->a->activate();
        Sanctum::actingAs($this->userA->fresh());
        $this->getJson('/api/agents')->assertOk();
    }

    public function test_super_admin_reaches_the_platform_but_not_tenant_surfaces(): void
    {
        $super = $this->superAdmin();
        // La 2FA est obligatoire pour le super admin : on la considère activée et prouvée.
        $super->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_enabled' => true])->save();
        $verified = [\App\Security\TwoFactorGate::SESSION_KEY => $super->id];

        $this->withSession($verified)->actingAs($super)->get('/platform')->assertOk();
        $this->withSession($verified)->actingAs($super)->get('/platform/tenants')->assertOk();

        Sanctum::actingAs($super);
        $this->getJson('/api/agents')->assertForbidden();
        $this->actingAs($super)->get('/m')->assertForbidden();
        $this->actingAs($super)->get('/master')->assertForbidden();
    }

    public function test_tenant_users_cannot_enter_the_platform(): void
    {
        foreach (['master', 'admin'] as $role) {
            $this->actingAs($this->userIn($this->a, $role))->get('/platform')->assertForbidden();
            $this->actingAs($this->userIn($this->a, $role))->get('/platform/tenants')->assertForbidden();
        }
    }

    // ---- provisionnement --------------------------------------------------------------------

    public function test_provisioning_creates_isolated_reference_data_and_an_admin(): void
    {
        $tenant = app(TenantProvisioner::class)->provision(
            ['name' => 'Société C'],
            ['name' => 'Admin C', 'email' => 'admin-c@example.com', 'password' => 'un-mot-de-passe-long'],
        );

        $admin = User::withoutGlobalScopes()->where('email', 'admin-c@example.com')->first();
        $this->assertSame($tenant->id, (int) $admin->tenant_id);
        $this->assertTrue($admin->hasRole('master'));
        $this->assertTrue($admin->hasActiveTenant());

        $this->actingAs($admin);
        $this->assertSame(['bank_transfer', 'cash', 'cheque', 'mobile_money'], PaymentMethod::orderBy('code')->pluck('code')->all());
        $this->assertSame(1, \App\Models\CaisseCategorie::where('is_system', true)->count());

        // Les données de référence de C n'ont pas touché A.
        $this->actingAs($this->userA);
        $this->assertSame(0, PaymentMethod::count());
    }

    // ---- tâches planifiées -------------------------------------------------------------------

    public function test_scheduled_commands_run_once_per_active_tenant_in_that_tenants_context(): void
    {
        $suspended = Tenant::create(['name' => 'Société suspendue']);
        $suspended->suspend();

        $probe = new class extends \Illuminate\Console\Command
        {
            use \App\Console\Commands\Concerns\RunsPerTenant;

            protected $signature = 'probe:tenant';

            public array $seen = [];

            public function handle(): int
            {
                $this->seen[] = [TenantContext::id(), Agent::count()];

                return 0;
            }
        };
        $probe->setLaravel(app());

        $probe->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput);

        $byTenant = collect($probe->seen)->mapWithKeys(fn ($row) => [$row[0] => $row[1]]);
        $this->assertEqualsCanonicalizing([1, $this->a->id, $this->b->id], $byTenant->keys()->all());
        $this->assertSame(1, $byTenant[$this->a->id], 'Chaque exécution ne voit que les agents de son tenant.');
        $this->assertSame(1, $byTenant[$this->b->id]);
        $this->assertArrayNotHasKey($suspended->id, $byTenant->all());
    }

    public function test_cached_figures_are_never_shared_between_tenants(): void
    {
        $this->actingAs($this->userA);
        $keyA = TenantContext::cacheKey('api_dashboard_stats');
        $this->actingAs($this->userB);

        $this->assertNotSame($keyA, TenantContext::cacheKey('api_dashboard_stats'));
    }

    public function test_platform_super_admin_command_creates_a_tenantless_account(): void
    {
        $this->artisan('platform:super-admin', ['email' => 'root@plateforme.test', '--password' => 'mot-de-passe-solide-1'])
            ->assertSuccessful();

        $user = User::withoutGlobalScopes()->where('email', 'root@plateforme.test')->first();
        $this->assertNull($user->tenant_id);
        $this->assertTrue($user->isSuperAdmin());

        // Refus d'un mot de passe faible et d'un compte déjà rattaché à un tenant.
        $this->artisan('platform:super-admin', ['email' => 'faible@plateforme.test', '--password' => 'court'])->assertFailed();
        $this->artisan('platform:super-admin', ['email' => $this->userA->email, '--password' => 'mot-de-passe-solide-1'])->assertFailed();
    }

    public function test_super_admin_creates_and_suspends_a_tenant_from_the_platform_panel(): void
    {
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('platform'));
        $this->actingAs($this->superAdmin());

        \Livewire\Livewire::test(\App\Filament\Platform\Resources\TenantResource\Pages\CreateTenant::class)
            ->fillForm([
                'name' => 'Société D', 'status' => 'active',
                'admin_name' => 'Admin D', 'admin_email' => 'admin-d@example.com', 'admin_password' => 'un-mot-de-passe-long',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tenant = Tenant::where('name', 'Société D')->first();
        $this->assertNotNull($tenant);
        $this->assertTrue(User::withoutGlobalScopes()->where('email', 'admin-d@example.com')->first()->hasRole('master'));

        \Livewire\Livewire::test(\App\Filament\Platform\Resources\TenantResource\Pages\ListTenants::class)
            ->callTableAction('suspend', $tenant);
        $this->assertFalse($tenant->fresh()->isActive());

        // Un master de tenant ne peut pas utiliser cette page.
        $this->actingAs($this->userA);
        \Livewire\Livewire::test(\App\Filament\Platform\Resources\TenantResource\Pages\ListTenants::class)->assertForbidden();
    }
}
