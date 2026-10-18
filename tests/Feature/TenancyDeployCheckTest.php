<?php

namespace Tests\Feature;

use App\Models\Agent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenancyDeployCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
        config(['filesystems.default' => 'local']);
        config(['security.authentication.2fa_enforce_from' => '2099-01-01']);
    }

    public function test_a_clean_installation_passes(): void
    {
        $this->assertSame(0, Artisan::call('tenancy:deploy-check'));
        $this->assertStringContainsString('Aucune erreur', Artisan::output());
    }

    public function test_files_readable_by_url_are_an_error_and_are_listed(): void
    {
        Storage::disk('public')->put('reports/rapport_quotidien_2026-08-25.pdf', 'x');
        Storage::disk('public')->put('tenants/2/reports/r.pdf', 'x');

        $this->assertSame(1, Artisan::call('tenancy:deploy-check'));
        $output = Artisan::output();
        $this->assertStringContainsString('Fichiers publics : reports/', $output);
        $this->assertStringContainsString('Fichiers publics : tenants/', $output);
        $this->assertStringContainsString('lisibles par URL', $output);
    }

    public function test_the_public_default_disk_is_flagged_and_production_debug_is_an_error(): void
    {
        config(['filesystems.default' => 'public']);
        Artisan::call('tenancy:deploy-check');
        $this->assertStringContainsString('le disque par défaut est lisible par URL', Artisan::output());

        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => true]);
        $this->assertSame(1, Artisan::call('tenancy:deploy-check'));
        $this->assertStringContainsString('APP_DEBUG', Artisan::output());
    }

    public function test_missing_2fa_enforcement_date_is_flagged(): void
    {
        config(['security.authentication.2fa_enforce_from' => null]);

        $this->assertSame(0, Artisan::call('tenancy:deploy-check'));
        $this->assertStringContainsString('SECURITY_2FA_ENFORCE_FROM vide', Artisan::output());
    }

    public function test_it_never_modifies_anything(): void
    {
        Storage::disk('public')->put('reports/x.pdf', 'x');
        Storage::disk('local')->put('exports/old.xlsx', 'x');
        Agent::factory()->create();
        $before = [Storage::disk('public')->allFiles(), Storage::disk('local')->allFiles(), Agent::withoutGlobalScopes()->count()];

        Artisan::call('tenancy:deploy-check');

        $this->assertSame($before, [Storage::disk('public')->allFiles(), Storage::disk('local')->allFiles(), Agent::withoutGlobalScopes()->count()]);
    }

    public function test_missing_tenant_roles_are_reported_before_platform_provisioning(): void
    {
        Role::query()->where('name', 'master')->delete();

        $this->assertSame(1, Artisan::call('tenancy:deploy-check'));
        $output = Artisan::output();

        $this->assertStringContainsString('Rôles tenant', $output);
        $this->assertStringContainsString('master', $output);
        $this->assertStringContainsString('RolesAndPermissionsSeeder', $output);
    }
}
