<?php

namespace Tests\Feature;

use App\Tenancy\BelongsToTenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientPanelTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_client_panel_resource_is_backed_by_a_tenant_scoped_model(): void
    {
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $model = $resource::getModel();
            $instance = new $model;

            $this->assertContains(
                BelongsToTenant::class,
                class_uses_recursive($model),
                "{$resource} utilise {$model}, qui n'est pas protégé par BelongsToTenant.",
            );
            $this->assertTrue(
                Schema::hasColumn($instance->getTable(), 'tenant_id'),
                "{$resource} utilise {$model}, dont la table ne contient pas tenant_id.",
            );
        }
    }

    public function test_platform_resources_are_never_registered_in_the_client_panel(): void
    {
        $clientResources = Filament::getPanel('admin')->getResources();
        $platformResources = Filament::getPanel('platform')->getResources();

        $this->assertNotEmpty($platformResources);
        $this->assertSame([], array_values(array_intersect($clientResources, $platformResources)));

        foreach ($platformResources as $resource) {
            $this->assertStringStartsWith('App\\Filament\\Platform\\Resources\\', $resource);
        }
    }
}
