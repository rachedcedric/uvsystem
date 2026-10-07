<?php

namespace Tests\Feature;

use App\Filament\Resources\AgentPartenaireResource;
use App\Filament\Resources\AgentSoldeResource;
use App\Filament\Resources\CaisseCategorieResource;
use App\Filament\Resources\PalierCommissionResource;
use App\Filament\Resources\PaymentMethodResource;
use App\Filament\Resources\RemboursementAgentResource;
use App\Filament\Resources\SousDistributeurResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\VenteUvResource;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MasterTenantAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $tenant = Tenant::create(['name' => 'Tenant administré']);
        $this->master = TenantContext::runAs($tenant->id, function () {
            $user = User::factory()->create();
            $user->assignRole('master');

            return $user;
        });

        $this->actingAs($this->master);
    }

    public function test_master_has_every_allowed_tenant_permission_and_no_forbidden_permission(): void
    {
        $allowed = Permission::query()
            ->whereNotIn('name', User::FORBIDDEN_TENANT_PERMISSIONS)
            ->pluck('name');

        foreach ($allowed as $permission) {
            $this->assertTrue($this->master->can($permission), "Permission tenant manquante : {$permission}");
        }

        foreach (User::FORBIDDEN_TENANT_PERMISSIONS as $permission) {
            $this->assertFalse($this->master->can($permission), "Permission interdite attribuée : {$permission}");
        }

        $this->assertContains('assign_roles', $allowed);
        $this->assertNotContains(User::ROLE_SUPER_ADMIN, User::assignableRoleNames());
    }

    public function test_master_can_reach_all_active_tenant_customization_surfaces(): void
    {
        foreach ([
            AgentSoldeResource::class,
            CaisseCategorieResource::class,
            PalierCommissionResource::class,
            PaymentMethodResource::class,
            SousDistributeurResource::class,
            UserResource::class,
        ] as $resource) {
            $this->assertTrue($resource::shouldRegisterNavigation(), "{$resource} est absent du menu Master.");
            $this->assertTrue($resource::canAccess(), "{$resource} refuse le Master.");
        }

        $this->get('/master')->assertOk();
        $this->get('/master/agent-soldes')->assertOk();
        $this->get('/master/caisse-categories')->assertOk();
        $this->get('/master/palier-commissions')->assertOk();
        $this->get('/master/payment-methods')->assertOk();
        $this->get('/master/sous-distributeurs')->assertOk();
        $this->get('/master/users')->assertOk();
    }

    public function test_inactive_partner_workflows_remain_hidden(): void
    {
        $this->assertFalse(AgentPartenaireResource::shouldRegisterNavigation());
        $this->assertFalse(VenteUvResource::shouldRegisterNavigation());
        $this->assertFalse(RemboursementAgentResource::shouldRegisterNavigation());
    }

    public function test_master_manages_tenant_users_but_cannot_delete_self_or_another_master(): void
    {
        $otherMaster = TenantContext::runAs($this->master->tenant_id, function () {
            $user = User::factory()->create();
            $user->assignRole('master');

            return $user;
        });
        $commercial = TenantContext::runAs($this->master->tenant_id, function () {
            $user = User::factory()->create();
            $user->assignRole('commercial');

            return $user;
        });

        $this->assertTrue(UserResource::canCreate());
        $this->assertTrue(UserResource::canEdit($commercial));
        $this->assertTrue(UserResource::canDelete($commercial));
        $this->assertFalse(UserResource::canDelete($this->master));
        $this->assertFalse(UserResource::canDelete($otherMaster));
    }

    public function test_a_tenant_admin_can_manage_roles_but_can_never_grant_master_even_with_a_forged_livewire_state(): void
    {
        $target = TenantContext::runAs($this->master->tenant_id, function () {
            $user = User::factory()->create();
            $user->assignRole('commercial');

            return $user;
        });

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->set('data.roles', [Role::findByName('admin')->getKey()])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($target->fresh()->hasRole('admin'));

        $tenantAdmin = TenantContext::runAs($this->master->tenant_id, function () {
            $user = User::factory()->create();
            $user->assignRole('admin');

            return $user;
        });
        $this->actingAs($tenantAdmin);

        // L'admin peut attribuer les rôles d'administration et métier…
        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->set('data.roles', [Role::findByName('comptable')->getKey()])
            ->call('save')
            ->assertHasNoErrors();
        $this->assertTrue($target->fresh()->hasRole('comptable'));

        // …mais jamais « master » : refusé avec un message, rien n'est enregistré.
        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->set('data.roles', [Role::findByName('master')->getKey()])
            ->call('save')
            ->assertHasErrors(['roles']);

        $target->refresh();
        $this->assertTrue($target->hasRole('comptable'));
        $this->assertFalse($target->hasRole('master'));
    }
}
