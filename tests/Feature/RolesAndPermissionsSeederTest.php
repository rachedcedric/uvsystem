<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesAndPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_never_assigns_the_master_role_to_a_platform_super_admin(): void
    {
        Role::findOrCreate(User::ROLE_SUPER_ADMIN, 'web');

        $superAdmin = TenantContext::runAs(null, fn () => User::factory()->create([
            'tenant_id' => null,
        ]));
        $superAdmin->assignRole(User::ROLE_SUPER_ADMIN);

        $this->seed(RolesAndPermissionsSeeder::class);

        $superAdmin->refresh();

        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertFalse($superAdmin->hasRole('master'));
    }

    public function test_it_still_assigns_master_to_the_first_tenant_user(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant initial']);
        $tenantUser = TenantContext::runAs($tenant->id, fn () => User::factory()->create());

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertTrue($tenantUser->fresh()->hasRole('master'));
    }
}
