<?php

namespace Tests\Feature;

use App\Filament\Pages\ImportPage;
use App\Models\Agent;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Correctifs de l'audit : import réservé à master/admin, anciennes ressources fermées, commandes de test sûres. */
class SecurityHardeningTest extends TestCase
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
            $user = User::factory()->create();
            $user->assignRole($role);

            return $user;
        });
    }

    public function test_the_web_import_page_is_reserved_to_master_and_admin_like_the_mobile_one(): void
    {
        foreach (['master', 'admin'] as $role) {
            $this->actingAs($this->userIn($role))->get('/master/import-page')->assertOk();
        }

        foreach (['comptable', 'commercial'] as $role) {
            $this->actingAs($this->userIn($role))->get('/master/import-page')->assertForbidden();
        }
    }

    public function test_the_import_actions_themselves_refuse_users_who_bypass_the_route(): void
    {
        $user = $this->userIn('commercial');
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Livewire rejoue mount() puis appelle l'action : le contrôle doit tenir aux deux endroits.
        Livewire::test(ImportPage::class)->assertForbidden();
        $this->assertFalse(ImportPage::canAccess());
    }

    public function test_the_import_page_is_not_offered_in_the_menu_to_users_who_cannot_use_it(): void
    {
        $this->actingAs($this->userIn('commercial'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $labels = collect(Filament::getNavigation())->flatMap(fn ($group) => collect($group->getItems())->map(fn ($item) => $item->getLabel()));

        $this->assertNotContains('Import CSV/Excel', $labels->all());
    }

    public function test_the_old_partner_resources_answer_403_instead_of_crashing(): void
    {
        $master = $this->userIn('master');

        foreach (['/master/vente-uvs', '/master/vente-uvs/create', '/master/remboursement-agents', '/master/remboursement-agents/create'] as $url) {
            $this->actingAs($master)->get($url)->assertForbidden();
        }
    }

    public function test_the_notification_test_command_never_creates_an_account_with_a_known_password(): void
    {
        Notification::fake();
        config(['services.resend.key' => 're_test']);

        Artisan::call('notifications:test', ['--tenant' => $this->tenant->id, '--email' => 'probe@example.test', 'type' => 'debt']);

        $user = TenantContext::withoutTenancy(fn () => User::withoutGlobalScopes()->where('email', 'probe@example.test')->first());
        $this->assertNotNull($user);
        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_the_test_data_seeder_refuses_to_run_on_a_non_development_host(): void
    {
        config(['app.url' => 'https://uvsystem.pro']);

        Artisan::call('db:seed', ['--class' => 'TestDataSeeder', '--force' => true]);

        $this->assertStringContainsString('Seeder refusé', Artisan::output());
        $this->assertSame(0, TenantContext::runAs($this->tenant->id, fn () => Agent::query()->count()));
    }
}
