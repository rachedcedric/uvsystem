<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Menu de la console plateforme : chaque élément dans son groupe, réservé au super admin. */
class PlatformNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        Filament::setCurrentPanel(Filament::getPanel('platform'));
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['tenant_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    /** @return array<string, list<string>> libellé du groupe => libellés des éléments, dans l'ordre d'affichage */
    private function menu(): array
    {
        return collect(Filament::getNavigation())->mapWithKeys(fn ($group) => [
            (string) $group->getLabel() => collect($group->getItems())->map(fn ($item) => $item->getLabel())->values()->all(),
        ])->all();
    }

    public function test_every_item_belongs_to_a_group_and_the_order_is_stable(): void
    {
        $this->actingAs($this->superAdmin());
        $menu = $this->menu();

        $this->assertArrayNotHasKey('', $menu, 'aucun élément ne doit rester hors groupe');
        $this->assertSame(['Pilotage', 'Entreprises', 'Abonnements', 'CMS', 'Système'], array_keys($menu));
        $this->assertSame(['Tableau de bord'], $menu['Pilotage']);
        $this->assertSame(['Entreprises'], $menu['Entreprises']);
        $this->assertSame(['Abonnements', 'Offres', 'Demandes de changement'], $menu['Abonnements']);
        $this->assertSame(['Page d\'accueil', 'Pages publiques', 'E-mails et messages', 'Réglages du SaaS'], $menu['CMS']);
        $this->assertContains('Sauvegardes', $menu['Système']);
    }

    public function test_the_dashboard_and_tenants_still_work_from_their_new_place(): void
    {
        config(['security.authentication.2fa_enabled' => false]);
        $this->actingAs($this->superAdmin());

        $this->get('/platform')->assertOk()->assertSee('Tableau de bord');
        $this->get('/platform/tenants')->assertOk()
            ->assertSeeInOrder(['<h1', 'Entreprises', '</h1>'], false);
        $this->assertSame(url('/platform'), Filament::getPanel('platform')->getUrl());
    }

    public function test_a_tenant_user_sees_no_platform_menu_and_cannot_open_it(): void
    {
        $tenant = Tenant::create(['name' => 'Société Test']);
        $master = TenantContext::runAs($tenant->id, function () {
            $user = User::factory()->create();
            $user->assignRole('master');

            return $user;
        });
        $this->actingAs($master);

        $this->get('/platform')->assertForbidden();
        $this->get('/platform/tenants')->assertForbidden();
        $this->assertSame([], $this->menu(), 'rien à afficher pour un compte qui n\'est pas super admin');
    }
}
