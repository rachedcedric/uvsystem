<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Moindre privilège : API réservée à master/admin (access_api), suppression d'agents, soldes et journal d'activité. */
class LeastPrivilegeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        config(['security.authentication.2fa_enabled' => false]);
        $this->tenant = Tenant::create(['name' => 'Société A']);
    }

    private function userIn(string $role): User
    {
        return TenantContext::runAs($this->tenant->id, function () use ($role) {
            $user = User::factory()->create(['password' => 'Un-Mot-De-Passe-12']);
            $user->assignRole($role);

            return $user;
        });
    }

    // ------------------------------------------------------------------------------------- API (access_api)

    public function test_only_master_and_admin_can_log_in_to_the_api_and_use_a_token(): void
    {
        foreach (['master', 'admin'] as $role) {
            $user = $this->userIn($role);
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Un-Mot-De-Passe-12'])->assertOk()->assertJsonPath('success', true);
            Sanctum::actingAs($user, ['*']);
            $this->getJson('/api/agents')->assertOk();
        }

        foreach (['comptable', 'commercial', User::ROLE_READ_ONLY] as $role) {
            $user = $this->userIn($role);
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Un-Mot-De-Passe-12'])->assertStatus(422);
            $this->assertSame(0, $user->tokens()->count(), "$role ne reçoit aucun token");

            // Même avec un token déjà émis (ancien compte, rôle modifié depuis) : refusé à chaque appel.
            Sanctum::actingAs($user, ['*']);
            $this->getJson('/api/agents')->assertForbidden();
            $this->getJson('/api/dashboard')->assertForbidden();
            $this->postJson('/api/agents', ['name' => 'X'])->assertForbidden();
        }
    }

    // ------------------------------------------------------------------------------------- permissions retirées

    public function test_the_commercial_can_no_longer_delete_agents_but_keeps_the_rest_of_his_job(): void
    {
        $commercial = $this->userIn('commercial');
        $agent = TenantContext::runAs($this->tenant->id, fn () => Agent::factory()->create());

        $this->assertFalse($commercial->can('delete_agents'));
        $this->assertFalse(Gate::forUser($commercial)->allows('delete', $agent));
        foreach (['view_agents', 'create_agents', 'edit_agents', 'export_agents', 'create_uv_transactions', 'create_fournisseurs'] as $kept) {
            $this->assertTrue($commercial->can($kept), $kept);
        }
    }

    public function test_the_comptable_can_no_longer_edit_balances_but_keeps_finance(): void
    {
        $comptable = $this->userIn('comptable');

        $this->assertFalse($comptable->can('edit_soldes'));
        $this->assertTrue($comptable->can('view_soldes'));
        $this->assertTrue($comptable->can('create_caisse'));
        $this->actingAs($comptable)->get('/master/agent-soldes/create')->assertForbidden();
    }

    /** Un compte par test : le panneau Filament garde l'utilisateur de la première requête d'un même test. */
    #[DataProvider('activityLogRoles')]
    public function test_the_activity_log_is_reserved_to_master_and_admin_on_web_and_mobile(string $role, bool $allowed): void
    {
        $user = $this->userIn($role);

        $this->assertSame($allowed, $user->can('view_activity_log'));
        $web = $this->actingAs($user)->get('/master/activity-logs');
        $mobile = $this->actingAs($user)->get('/m/activite');

        $allowed ? $web->assertOk() : $web->assertForbidden();
        $allowed ? $mobile->assertOk() : $mobile->assertForbidden();
    }

    /** @return array<string, array{string, bool}> */
    public static function activityLogRoles(): array
    {
        return [
            'master' => ['master', true],
            'admin' => ['admin', true],
            'comptable' => ['comptable', false],
            'commercial' => ['commercial', false],
            'lecture seule' => [User::ROLE_READ_ONLY, false],
        ];
    }

    public function test_re_running_the_seeder_removes_the_permissions_from_existing_databases(): void
    {
        Role::findByName('commercial')->givePermissionTo(['delete_agents', 'view_activity_log']);
        Role::findByName('comptable')->givePermissionTo(['edit_soldes', 'view_activity_log']);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertFalse(Role::findByName('commercial')->hasPermissionTo('delete_agents'));
        $this->assertFalse(Role::findByName('commercial')->hasPermissionTo('view_activity_log'));
        $this->assertFalse(Role::findByName('comptable')->hasPermissionTo('edit_soldes'));
        $this->assertFalse(Role::findByName('comptable')->hasPermissionTo('view_activity_log'));
    }

    public function test_master_and_admin_keep_every_one_of_these_capabilities(): void
    {
        foreach (['master', 'admin'] as $role) {
            $user = $this->userIn($role);
            foreach (['access_api', 'delete_agents', 'edit_soldes', 'view_activity_log', 'view_users', 'edit_system_settings'] as $permission) {
                $this->assertTrue($user->can($permission), "$role / $permission");
            }
        }
    }
}
