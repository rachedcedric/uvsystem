<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Connexion de l'espace entreprise : la présentation change, l'authentification reste celle de Filament. */
class TenantLoginPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function tenantUser(string $role = 'master', string $password = 'Un-Mot-De-Passe-12', ?Tenant $tenant = null): User
    {
        $tenant ??= Tenant::create(['name' => 'Société '.uniqid()]);

        return TenantContext::runAs($tenant->id, function () use ($role, $password) {
            $user = User::factory()->create(['password' => bcrypt($password)]);
            $user->assignRole($role);

            return $user;
        });
    }

    public function test_the_branded_login_page_is_served(): void
    {
        $html = $this->get(route('filament.admin.auth.login'))->assertOk()
            ->assertSee('Bon retour')
            ->assertSee('Connectez-vous pour suivre vos ventes, vos dettes et votre caisse.')
            ->assertSee('Vos flux UV.')
            ->assertSee('Une seule tour de contrôle.', false)
            ->assertSee('Espace entreprise')
            ->assertSee('images/logo-wide.png', false)
            ->assertSee('css/filament-auth.css', false)
            ->assertSee('Mot de passe oublié ?')
            ->assertSee('Commencer gratuitement')
            ->getContent();

        $this->assertStringContainsString('class="pf-aside"', $html);
        $this->assertStringContainsString('wire:submit="authenticate"', $html, 'le formulaire reste celui de Filament');
        // Le logo vient du thème, une seule fois (le logo standard de Filament est désactivé sur cette page).
        $this->assertSame(1, substr_count($html, 'class="pf-logo"'));
    }

    public function test_the_signup_and_mobile_links_point_to_real_pages(): void
    {
        $html = $this->get(route('filament.admin.auth.login'))->getContent();

        $this->assertStringContainsString('href="/offres"', $html, 'un prospect peut démarrer depuis la page de connexion');
        $this->assertStringContainsString('href="/m/login"', $html);
        $this->get('/offres')->assertOk();
        $this->get('/m/login')->assertOk();
    }

    public function test_only_the_labels_and_the_logo_behaviour_are_redefined(): void
    {
        $this->assertTrue(is_subclass_of(Login::class, \Filament\Pages\Auth\Login::class));
        $overridden = collect((new \ReflectionClass(Login::class))->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED))
            ->filter(fn ($m) => $m->class === Login::class)->map->getName()->sort()->values()->all();

        $this->assertSame(['getHeading', 'getSubHeading', 'hasLogo'], $overridden, 'aucune logique d\'accès n\'est redéfinie');
    }

    public function test_a_tenant_master_signs_in(): void
    {
        $master = $this->tenantUser();

        Livewire::test(Login::class)->fillForm(['email' => $master->email, 'password' => 'Un-Mot-De-Passe-12'])->call('authenticate')->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($master);
    }

    public function test_wrong_credentials_and_accounts_without_access_are_refused(): void
    {
        $master = $this->tenantUser();
        Livewire::test(Login::class)->fillForm(['email' => $master->email, 'password' => 'mauvais'])->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();

        // Entreprise suspendue : identifiants valides, aucun accès.
        $suspendedTenant = Tenant::create(['name' => 'Suspendue '.uniqid()]);
        $user = $this->tenantUser('master', 'Un-Mot-De-Passe-12', $suspendedTenant);
        $suspendedTenant->suspend();
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'Un-Mot-De-Passe-12'])->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();

        // Super admin de plateforme : son espace est /platform, pas celui des entreprises.
        $admin = User::factory()->create(['tenant_id' => null, 'password' => bcrypt('Un-Mot-De-Passe-12')]);
        $admin->assignRole('super_admin');
        Livewire::test(Login::class)->fillForm(['email' => $admin->email, 'password' => 'Un-Mot-De-Passe-12'])->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();
    }

    public function test_the_brand_panel_and_logo_never_appear_inside_the_console(): void
    {
        config(['security.authentication.2fa_enabled' => false]);
        $this->actingAs($this->tenantUser());

        $html = $this->get(Filament::getPanel('admin')->getUrl())->assertOk()->getContent(); // indépendant du chemin du panneau

        $this->assertStringNotContainsString('class="pf-aside"', $html);
        $this->assertStringNotContainsString('class="pf-logo"', $html);
        $this->assertStringContainsString('Tableau de bord', $html);
    }

    public function test_the_platform_login_is_unchanged_by_the_shared_stylesheet(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('platform'));

        $this->get('/platform/login')->assertOk()->assertSee('Console d\'administration')->assertSee('Console plateforme')->assertDontSee('Espace entreprise');
    }

    public function test_the_stylesheet_only_offsets_the_layout_when_the_brand_panel_exists(): void
    {
        $css = file_get_contents(public_path('css/filament-auth.css'));

        $this->assertStringContainsString('.pf-aside ~ .fi-simple-layout', $css);
        $this->assertStringNotContainsString(':is(.fi-panel-platform, .fi-panel-admin) .fi-simple-layout {
        padding-left', $css);
        $this->assertStringNotContainsString('.fi-topbar', $css, 'aucune règle ne touche la barre du haut de la console entreprise');
    }
}
