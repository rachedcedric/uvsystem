<?php

namespace Tests\Feature;

use App\Filament\Platform\Pages\BackupPage;
use App\Models\Agent;
use App\Models\Tenant;
use App\Models\User;
use App\Security\TwoFactorGate;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class TenantExportAndBackupTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);

        $this->a = Tenant::create(['name' => 'Société A']);
        $this->b = Tenant::create(['name' => 'Société B']);
        TenantContext::runAs($this->a->id, fn () => Agent::factory()->create(['name' => 'Agent-de-A']));
        TenantContext::runAs($this->b->id, fn () => Agent::factory()->create(['name' => 'Agent-de-B']));
    }

    private function userIn(Tenant $tenant, string $role): User
    {
        return TenantContext::runAs($tenant->id, function () use ($role, $tenant) {
            $user = User::factory()->create(['email' => "{$role}-{$tenant->id}@example.com"]);
            $user->assignRole($role);

            return $user;
        });
    }

    private function readZip($response): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();

        return $files;
    }

    // ---- export des données d'un tenant ---------------------------------------------------------------

    public function test_a_master_exports_only_its_own_tenants_data(): void
    {
        $response = $this->actingAs($this->userIn($this->a, 'master'))->get('/exports/mes-donnees');
        $response->assertOk();

        $files = $this->readZip($response);

        $this->assertStringContainsString('Agent-de-A', $files['agents.csv']);
        $this->assertStringNotContainsString('Agent-de-B', $files['agents.csv']);
        $this->assertStringContainsString('master-'.$this->a->id.'@example.com', $files['users.csv']);
        $this->assertStringNotContainsString('@example.com'.PHP_EOL.'master-'.$this->b->id, $files['users.csv']);
        $this->assertStringNotContainsString('master-'.$this->b->id.'@example.com', $files['users.csv']);
        $this->assertStringNotContainsString('password', strtolower($files['users.csv']));
        $this->assertSame('Société A', json_decode($files['manifest.json'], true)['tenant']);
    }

    public function test_only_the_master_can_export_and_a_suspended_tenant_cannot(): void
    {
        $this->actingAs($this->userIn($this->a, 'admin'))->get('/exports/mes-donnees')->assertForbidden();
        $this->actingAs($this->userIn($this->a, 'commercial'))->get('/exports/mes-donnees')->assertForbidden();

        $master = $this->userIn($this->a, 'master');
        $this->a->suspend();
        $this->actingAs($master->fresh())->get('/exports/mes-donnees')->assertForbidden();
    }

    public function test_csv_cells_cannot_inject_spreadsheet_formulas(): void
    {
        TenantContext::runAs($this->a->id, fn () => Agent::factory()->create(['name' => '=HYPERLINK("http://evil","x")']));

        $files = $this->readZip($this->actingAs($this->userIn($this->a, 'master'))->get('/exports/mes-donnees'));

        $this->assertStringContainsString("'=HYPERLINK", $files['agents.csv']);
    }

    // ---- sauvegardes dans /platform ---------------------------------------------------------------------

    private function superAdmin(): User
    {
        $user = TenantContext::runAs(null, fn () => User::factory()->create());
        $user->assignRole(User::ROLE_SUPER_ADMIN);
        $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_enabled' => true])->save();

        return $user->fresh();
    }

    public function test_backups_live_in_the_platform_panel_and_only_the_super_admin_sees_them(): void
    {
        Storage::fake('local');
        $dir = config('app.name', 'uv-system');
        Storage::disk('local')->put("{$dir}/backup-1.zip", 'zip');

        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $super = $this->superAdmin();

        $this->withSession([TwoFactorGate::SESSION_KEY => $super->id])->actingAs($super)
            ->get('/platform/backup-page')->assertOk()->assertSee('backup-1.zip');

        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs($this->userIn($this->a, 'master'))->get('/platform/backup-page')->assertForbidden();
    }

    public function test_backup_deletion_cannot_reach_files_outside_the_backup_folder(): void
    {
        Storage::fake('local');
        $dir = config('app.name', 'uv-system');
        Storage::disk('local')->put("{$dir}/backup-1.zip", 'zip');
        Storage::disk('local')->put('secret.txt', 'top secret');
        Storage::disk('local')->put("tenants/{$this->a->id}/exports/a.xlsx", 'données de A');

        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs($this->superAdmin());

        foreach (['secret.txt', "tenants/{$this->a->id}/exports/a.xlsx", '../secret.txt', 'secret.txt.zip'] as $path) {
            Livewire::test(BackupPage::class)->call('deleteBackup', $path);
        }

        Storage::disk('local')->assertExists('secret.txt');
        Storage::disk('local')->assertExists("tenants/{$this->a->id}/exports/a.xlsx");

        Livewire::test(BackupPage::class)->call('deleteBackup', "{$dir}/backup-1.zip");
        Storage::disk('local')->assertMissing("{$dir}/backup-1.zip");
    }
}
