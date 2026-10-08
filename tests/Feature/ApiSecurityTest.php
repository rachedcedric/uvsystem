<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Fournisseur;
use App\Models\Remboursement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase "correctifs" de l'audit : inscription publique, API sans autorisation,
 * IDOR des reçus, exports publics. Chaque test échouait avant le correctif.
 */
class ApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** Rôle métier minimal : accède au panneau mais n'a que les permissions listées. */
    private function userWithOnly(array $permissions): User
    {
        $role = Role::findByName('commercial', 'web');
        // L'API exige d'abord access_api (réservé à master/admin) ; ces tests vérifient ensuite chaque point d'accès.
        $role->syncPermissions(Permission::whereIn('name', array_merge(['access_api'], $permissions))->get());

        return $this->userWithRole('commercial');
    }

    private function fournisseur(): Fournisseur
    {
        return Fournisseur::forceCreate(['name' => 'Fournisseur test', 'type' => 'BANQUE', 'is_active' => true]);
    }

    // ---- V1 : plus d'inscription publique -------------------------------------------------

    public function test_public_registration_is_gone(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Intrus', 'email' => 'intrus@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'device_name' => 'x',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'intrus@example.com']);
    }

    public function test_login_is_refused_for_accounts_without_a_business_role(): void
    {
        User::factory()->create(['email' => 'orphelin@example.com', 'password' => 'password123']);

        $this->postJson('/api/auth/login', ['email' => 'orphelin@example.com', 'password' => 'password123'])
            ->assertStatus(422);
    }

    // ---- V2 : API sans autorisation --------------------------------------------------------

    public function test_roleless_token_cannot_reach_any_protected_endpoint(): void
    {
        $fournisseur = $this->fournisseur();
        Sanctum::actingAs(User::factory()->create());

        foreach (['/api/dashboard', '/api/reports', '/api/activities', '/api/fournisseurs', '/api/agents'] as $url) {
            $this->getJson($url)->assertForbidden();
        }

        $this->deleteJson("/api/fournisseurs/{$fournisseur->id}")->assertForbidden();
        $this->assertNotSoftDeleted($fournisseur);
    }

    public function test_staff_without_the_permission_is_denied_per_endpoint(): void
    {
        $fournisseur = $this->fournisseur();
        Sanctum::actingAs($this->userWithOnly(['view_dashboard']));

        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/reports')->assertForbidden();
        $this->getJson('/api/activities')->assertForbidden();
        $this->getJson('/api/fournisseurs')->assertForbidden();
        $this->getJson("/api/fournisseurs/{$fournisseur->id}")->assertForbidden();
        $this->putJson("/api/fournisseurs/{$fournisseur->id}", ['name' => 'Piraté'])->assertForbidden();
        $this->deleteJson("/api/fournisseurs/{$fournisseur->id}")->assertForbidden();
        $this->postJson('/api/fournisseurs', ['name' => 'Nouveau', 'type' => 'bank'])->assertForbidden();
        $this->getJson('/api/transactions/stats/daily')->assertForbidden();
        $this->getJson('/api/remboursements-stats')->assertForbidden();

        $this->assertSame('Fournisseur test', $fournisseur->fresh()->name);
    }

    public function test_agent_sub_resources_require_their_own_permission(): void
    {
        $agent = Agent::factory()->create();
        Sanctum::actingAs($this->userWithOnly(['view_agents']));

        $this->getJson("/api/agents/{$agent->id}/situation")->assertOk();
        $this->getJson("/api/agents/{$agent->id}/transactions")->assertForbidden();
        $this->getJson("/api/agents/{$agent->id}/remboursements")->assertForbidden();
    }

    public function test_master_still_has_access(): void
    {
        $this->fournisseur();
        Sanctum::actingAs($this->userWithRole('master'));

        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/fournisseurs')->assertOk();
        $this->getJson('/api/activities')->assertOk();
    }

    public function test_fournisseur_listing_ignores_unknown_sort_columns(): void
    {
        $this->fournisseur();
        Sanctum::actingAs($this->userWithRole('master'));

        $this->getJson('/api/fournisseurs?sort_by=colonne_inexistante;drop&sort_order=sideways&per_page=100000')
            ->assertOk()
            ->assertJsonPath('per_page', 100);
    }

    public function test_user_payload_never_exposes_two_factor_material(): void
    {
        $user = $this->userWithRole('master');
        $user->forceFill(['two_factor_secret' => 'SECRET', 'two_factor_recovery_codes' => ['a', 'b']])->save();
        Sanctum::actingAs($user);

        $body = $this->getJson('/api/auth/user')->assertOk()->json('data');

        $this->assertSame(['id', 'name', 'email', 'roles', 'two_factor_enabled'], array_keys($body));
    }

    public function test_api_tokens_expire(): void
    {
        $this->assertGreaterThan(0, (int) config('sanctum.expiration'));
    }

    // ---- V3 : IDOR des reçus ----------------------------------------------------------------

    private function remboursement(): Remboursement
    {
        $agent = Agent::factory()->create();

        return Remboursement::forceCreate([
            'agent_id' => $agent->id,
            'amount' => 1000,
            'payment_method' => 'cash',
            'reference' => 'REF-'.uniqid(),
            'payment_date' => now(),
            'status' => 'completed',
        ]);
    }

    public function test_receipt_requires_the_view_permission(): void
    {
        $remboursement = $this->remboursement();

        $this->get("/remboursement/{$remboursement->id}/receipt")->assertRedirect(); // invité

        $this->actingAs(User::factory()->create())
            ->get("/remboursement/{$remboursement->id}/receipt")->assertForbidden();
        $this->actingAs(User::factory()->create())
            ->get("/remboursement/{$remboursement->id}/receipt/download")->assertForbidden();

        $this->actingAs($this->userWithOnly(['view_agents']))
            ->get("/remboursement/{$remboursement->id}/receipt")->assertForbidden();

        $this->actingAs($this->userWithRole('master'))
            ->get("/remboursement/{$remboursement->id}/receipt")->assertOk();
    }

    // ---- V4 : exports ------------------------------------------------------------------------

    public function test_export_download_is_permission_gated_and_private(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('tenants/1/exports/test.xlsx', 'contenu'); // tenant de repli des tests

        $this->actingAs(User::factory()->create())
            ->get('/exports/download/test.xlsx')->assertForbidden();

        $this->actingAs($this->userWithOnly(['view_agents']))
            ->get('/exports/download/test.xlsx')->assertForbidden();

        $this->actingAs($this->userWithRole('master'))
            ->get('/exports/download/test.xlsx')->assertOk();

        // un fichier présent seulement sur le disque public n'est plus servi
        Storage::fake('public');
        Storage::disk('public')->put('exports/ancien.xlsx', 'contenu');
        $this->actingAs($this->userWithRole('admin'))
            ->get('/exports/download/ancien.xlsx')->assertNotFound();
    }

    public function test_fournisseur_api_matches_the_real_schema(): void
    {
        Sanctum::actingAs($this->userWithRole('master'));

        $id = $this->postJson('/api/fournisseurs', ['name' => 'Banque Test', 'type' => 'bank', 'contact' => '0102030405'])
            ->assertCreated()->json('data.id');

        $this->assertSame('BANQUE', Fournisseur::find($id)->type);
        $this->getJson('/api/fournisseurs?search=0102030405&type=bank')->assertOk()->assertJsonPath('total', 1);
        $this->postJson('/api/fournisseurs', ['name' => 'X', 'type' => 'other'])->assertStatus(422);
    }

    public function test_the_unauthenticated_package_webhook_route_is_unreachable(): void
    {
        $this->postJson('/resend/webhook', ['type' => 'email.bounced', 'data' => ['to' => ['a@example.com']]])->assertNotFound();

        // la route de l'application, elle, existe et refuse une requête non signée
        $this->postJson('/api/webhooks/resend', ['type' => 'email.bounced'])->assertStatus(401);
    }
}
