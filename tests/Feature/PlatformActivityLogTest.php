<?php

namespace Tests\Feature;

use App\Filament\Platform\Resources\ActivityLogResource\Pages\ListActivityLogs;
use App\Models\Activity;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('platform'));
    }

    public function test_the_platform_journal_only_shows_global_activity_to_the_super_admin(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant journal']);
        $master = TenantContext::runAs($tenant->id, function () {
            $user = User::factory()->create();
            $user->assignRole('master');

            return $user;
        });
        $superAdmin = TenantContext::runAs(null, function () {
            $user = User::factory()->create(['tenant_id' => null]);
            $user->assignRole(User::ROLE_SUPER_ADMIN);

            return $user;
        });

        $tenantActivity = TenantContext::runAs($tenant->id, fn () => Activity::forceCreate([
            'log_name' => 'tenant',
            'description' => 'Action du tenant',
            'causer_type' => User::class,
            'causer_id' => $master->id,
        ]));
        $platformActivity = TenantContext::runAs(null, fn () => Activity::forceCreate([
            'log_name' => 'platform',
            'description' => 'Action de plateforme',
            'causer_type' => User::class,
            'causer_id' => $superAdmin->id,
        ]));

        $this->actingAs($superAdmin);
        Livewire::test(ListActivityLogs::class)
            ->assertCanSeeTableRecords([$platformActivity])
            ->assertCanNotSeeTableRecords([$tenantActivity]);

        $this->actingAs($master);
        Livewire::test(ListActivityLogs::class)->assertForbidden();
    }
}
