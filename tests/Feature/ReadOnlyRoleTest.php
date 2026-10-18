<?php

namespace Tests\Feature;

use App\Filament\Resources\AgentResource\Pages\CreateAgent;
use App\Livewire\Mobile\Remboursements\Create as RemboursementCreate;
use App\Livewire\Mobile\Ventes\Create as VenteCreate;
use App\Models\Agent;
use App\Models\Tenant;
use App\Models\User;
use App\Security\ReadOnlyGuard;
use App\Security\RoleAssignmentGuard;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Rôle « lecture seule » : il consulte, il ne modifie rien. Les protections sont vérifiées côté serveur
 * (permissions, politiques, routes, Livewire, API, modèles) et pas seulement par des boutons masqués.
 */
class ReadOnlyRoleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    private User $ro;

    private User $master;

    private Agent $agentA;

    private Agent $agentB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        config(['security.authentication.2fa_enabled' => false]);
        $this->a = Tenant::create(['name' => 'Société A']);
        $this->b = Tenant::create(['name' => 'Société B']);
        $this->ro = $this->userIn($this->a, User::ROLE_READ_ONLY);
        $this->master = $this->userIn($this->a, 'master');
        $this->agentA = TenantContext::runAs($this->a->id, fn () => Agent::factory()->create(['name' => 'Agent Alpha']));
        $this->agentB = TenantContext::runAs($this->b->id, fn () => Agent::factory()->create(['name' => 'Agent Bravo']));
    }

    private function userIn(Tenant $tenant, string $role): User
    {
        return TenantContext::runAs($tenant->id, function () use ($role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            return $user;
        });
    }

    // ------------------------------------------------------------------ le rôle lui-même

    public function test_the_role_only_holds_view_permissions(): void
    {
        $permissions = Role::findByName(User::ROLE_READ_ONLY)->permissions->pluck('name');

        $this->assertNotEmpty($permissions);
        $this->assertTrue($permissions->every(fn (string $name) => str_starts_with($name, 'view_')), $permissions->implode(', '));
        foreach (['view_users', 'view_system_settings', 'view_activity_log', 'view_reports'] as $sensitive) {
            $this->assertFalse($this->ro->can($sensitive), $sensitive);
        }
        foreach (['access_api', 'assign_roles', 'create_agents', 'edit_agents', 'delete_agents', 'export_agents', 'export_reports', 'export_uv_transactions', 'edit_soldes', 'create_caisse'] as $forbidden) {
            $this->assertFalse($this->ro->can($forbidden), $forbidden);
        }
    }

    public function test_the_role_is_a_tenant_role_that_can_log_in_but_not_a_platform_one(): void
    {
        $this->assertContains(User::ROLE_READ_ONLY, User::TENANT_ROLES);
        $this->assertTrue($this->ro->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($this->ro->canAccessPanel(Filament::getPanel('platform')));
        $this->assertTrue($this->ro->isReadOnly());
        $this->assertFalse($this->master->isReadOnly());
    }

    public function test_the_role_cannot_be_combined_and_cannot_hand_out_roles(): void
    {
        $this->assertSame([], RoleAssignmentGuard::assignableFor($this->ro));

        $target = $this->userIn($this->a, 'comptable');
        RoleAssignmentGuard::assertCanAssign($this->master, $target, [User::ROLE_READ_ONLY]);

        $this->expectException(ValidationException::class);
        RoleAssignmentGuard::assertCanAssign($this->master, $target, [User::ROLE_READ_ONLY, 'comptable']);
    }

    // ------------------------------------------------------------------ ce qu'il peut consulter

    public function test_it_can_read_the_authorised_screens_on_web_and_mobile(): void
    {
        foreach (['/master', '/master/agents', '/master/uv-transactions', '/master/remboursements', '/master/approvisionnements', '/master/commissions', '/master/ma-caisse', '/master/charges',
            '/m', '/m/agents', '/m/ventes', '/m/remboursements', '/m/approvisionnements', '/m/commissions', '/m/dettes', '/m/statistiques', '/m/plus', '/m/notifications', '/m/securite'] as $url) {
            $this->actingAs($this->ro)->get($url)->assertOk();
        }

        $this->actingAs($this->ro)->get('/m/agents/'.$this->agentA->id)->assertOk()->assertSee('Agent Alpha');
    }

    public function test_it_sees_no_creation_or_administration_entry_in_the_mobile_menu(): void
    {
        $html = $this->actingAs($this->ro)->get('/m/plus')->assertOk()->getContent();

        foreach (['Import de données', 'Mon abonnement', 'Profil de l\'entreprise', 'Utilisateurs', 'Diagnostic des notifications', 'Sauvegardes'] as $label) {
            $this->assertStringNotContainsString($label, $html, $label);
        }
        $this->assertStringContainsString('Lecture seule', $html);
    }

    // ------------------------------------------------------------------ ce qu'il ne peut pas faire (routes)

    public function test_creation_administration_and_export_routes_are_refused_by_the_server(): void
    {
        $forbidden = [
            '/master/agents/create', '/master/uv-transactions/create', '/master/remboursements/create', '/master/approvisionnements/create', '/master/fournisseurs/create',
            '/master/agent-partenaires/create', '/master/users', '/master/users/create', '/master/payment-methods', '/master/palier-commissions', '/master/import-page', '/master/mon-abonnement', '/master/profil-entreprise',
            '/master/reports-page', '/master/activity-logs',
            '/m/ventes/nouvelle', '/m/remboursements/nouveau', '/m/approvisionnements/nouveau', '/m/caisse/mouvement', '/m/charges/nouvelle',
            '/m/import', '/m/abonnement', '/m/profil-entreprise', '/m/rapports', '/m/activite', '/m/g/utilisateurs', '/m/g/utilisateurs/nouveau', '/m/g/paliers/nouveau', '/m/g/agents/nouveau',
            '/m/sauvegardes', '/m/diagnostic-notifications',
            '/m/exports/commissions/pdf', '/m/exports/periode/ventes/today/pdf', '/m/exports/periode/remboursements/all/xlsx', '/m/exports/charges/all/pdf',
            '/m/exports/rapport/transactions/pdf', '/m/exports/fiche-agent/'.$this->agentA->id, '/m/exports/dette-agent/'.$this->agentA->id.'/pdf',
            '/m/exports/caisse/historique', '/exports/mes-donnees',
        ];

        $leaks = [];
        foreach ($forbidden as $url) {
            $status = $this->actingAs($this->ro)->get($url)->getStatusCode();
            if (! in_array($status, [403, 404], true)) {
                $leaks[] = "$url -> $status";
            }
        }

        $this->assertSame([], $leaks);
    }

    public function test_the_partner_agents_resource_is_closed_to_every_role(): void
    {
        // Un master n'a que des agents : plus d'agents partenaires, quel que soit le rôle.
        $this->actingAs($this->userIn($this->a, 'comptable'))->get('/master/agent-partenaires/create')->assertForbidden();
        $this->actingAs($this->userIn($this->a, 'commercial'))->get('/master/agent-partenaires/create')->assertForbidden();
        $this->actingAs($this->ro)->get('/master/agent-partenaires')->assertForbidden();
    }

    public function test_no_edit_page_opens_for_a_read_only_account_even_on_a_resource_without_policy(): void
    {
        $this->actingAs($this->ro)->get('/master/agents/'.$this->agentA->id.'/edit')->assertForbidden();
        $this->actingAs($this->master)->get('/master/agents/'.$this->agentA->id.'/edit')->assertOk();
    }

    // ------------------------------------------------------------------ contournements directs

    public function test_livewire_actions_cannot_be_replayed_to_write(): void
    {
        $this->actingAs($this->ro);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateAgent::class)->assertForbidden();
        Livewire::test(VenteCreate::class)->assertForbidden();
        Livewire::test(RemboursementCreate::class)->assertForbidden();
    }

    public function test_the_server_refuses_every_model_write_whatever_the_path(): void
    {
        $this->actingAs($this->ro);
        $agent = TenantContext::runAs($this->a->id, fn () => Agent::query()->find($this->agentA->id));

        $attempts = [
            'create' => fn () => Agent::create(['name' => 'Pirate', 'phone' => '0700000001', 'status' => 'active', 'debt_limit' => 1, 'commission_rate' => 1]),
            'update' => fn () => $agent->update(['name' => 'Renommé']),
            'saveQuietly' => fn () => $agent->forceFill(['current_debt' => 999])->saveQuietly(),
            'withoutEvents' => fn () => Agent::withoutEvents(fn () => $agent->forceFill(['name' => 'Silencieux'])->save()),
            'delete' => fn () => $agent->delete(),
        ];

        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                $this->fail("L'écriture « $label » a réussi pour un compte en lecture seule.");
            } catch (AuthorizationException $e) {
                $this->assertSame(ReadOnlyGuard::message(), $e->getMessage());
            }
        }

        $fresh = TenantContext::runAs($this->a->id, fn () => Agent::query()->find($this->agentA->id));
        $this->assertSame('Agent Alpha', $fresh->name);
        $this->assertSame(1, TenantContext::runAs($this->a->id, fn () => Agent::query()->count()));
    }

    public function test_the_api_refuses_writes_with_a_valid_token(): void
    {
        Sanctum::actingAs($this->ro, ['*']);

        $payload = ['name' => 'Via API', 'phone' => '0700000002', 'status' => 'active', 'debt_limit' => 1, 'commission_rate' => 1];
        $this->postJson('/api/agents', $payload)->assertForbidden();
        $this->putJson('/api/agents/'.$this->agentA->id, ['name' => 'Renommé'])->assertForbidden();
        $this->deleteJson('/api/agents/'.$this->agentA->id)->assertForbidden();

        $this->assertSame('Agent Alpha', TenantContext::runAs($this->a->id, fn () => Agent::query()->find($this->agentA->id))->name);
    }

    public function test_it_cannot_change_roles_tenant_or_permissions_of_anyone(): void
    {
        $this->actingAs($this->ro);
        $other = TenantContext::runAs($this->a->id, fn () => User::query()->find($this->master->id));

        $this->expectException(AuthorizationException::class);
        $other->update(['name' => 'Piraté']);
    }

    // ------------------------------------------------------------------ ce qui reste permis

    public function test_the_account_stays_manageable_by_its_owner_and_the_system_can_log(): void
    {
        $this->actingAs($this->ro);

        $this->ro->forceFill(['name' => 'Nouveau nom'])->save();
        $this->assertSame('Nouveau nom', $this->ro->fresh()->name);

        activity()->causedBy($this->ro)->log('consultation');
        $this->assertDatabaseHas('activity_log', ['description' => 'consultation']);

        $this->post('/security/password', [
            'current_password' => 'password',
            'password' => 'Un-Nouveau-Mot-2026',
            'password_confirmation' => 'Un-Nouveau-Mot-2026',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Un-Nouveau-Mot-2026', $this->ro->fresh()->password));
    }

    public function test_trusted_server_code_is_not_restricted(): void
    {
        $this->actingAs($this->ro);

        $created = TenantContext::runAs($this->a->id, fn () => Agent::factory()->create(['name' => 'Créé par le système']));

        $this->assertNotNull($created->id);
    }

    public function test_a_master_who_also_holds_the_read_only_role_is_read_only_fail_closed(): void
    {
        $this->master->assignRole(User::ROLE_READ_ONLY);
        $this->actingAs($this->master->fresh());

        $this->expectException(AuthorizationException::class);
        Agent::create(['name' => 'Interdit', 'phone' => '0700000003', 'status' => 'active', 'debt_limit' => 1, 'commission_rate' => 1]);
    }

    // ------------------------------------------------------------------ isolation maintenue

    public function test_it_never_sees_the_other_company(): void
    {
        $this->actingAs($this->ro)->get('/m/agents')->assertOk()->assertSee('Agent Alpha')->assertDontSee('Agent Bravo');
        $this->actingAs($this->ro)->get('/m/agents/'.$this->agentB->id)->assertNotFound();
        $this->actingAs($this->ro)->get('/master/agents/'.$this->agentB->id.'/edit')->assertForbidden(); // refusé avant même la recherche de l'enregistrement
        $this->actingAs($this->master)->get('/master/agents/'.$this->agentB->id.'/edit')->assertNotFound();
    }
}
