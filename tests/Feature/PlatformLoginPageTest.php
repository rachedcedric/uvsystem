<?php

namespace Tests\Feature;

use App\Filament\Platform\Pages\Auth\Login;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Connexion de la console plateforme : la présentation change, l'authentification reste celle de Filament. */
class PlatformLoginPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        Filament::setCurrentPanel(Filament::getPanel('platform'));
    }

    private function superAdmin(string $password = 'Un-Mot-De-Passe-12'): User
    {
        $user = User::factory()->create(['tenant_id' => null, 'password' => bcrypt($password)]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_the_branded_login_page_is_served_with_its_brand_assets(): void
    {
        $html = $this->get('/platform/login')->assertOk()
            ->assertSee('Console d\'administration')
            ->assertSee('Accès réservé aux administrateurs de la plateforme.')
            ->assertSee(config('branding.name'))
            ->assertSee('Pilotez toutes vos entreprises clientes')
            ->assertSee('images/logo-wide.png', false)
            ->assertSee('css/filament-auth.css', false)
            ->assertSee('Mot de passe oublié ?')
            ->getContent();

        $this->assertFileExists(public_path('css/filament-auth.css'));
        $this->assertFileExists(public_path('images/logo-wide.png'));
        $this->assertStringContainsString('class="pf-aside"', $html);
        // Le formulaire est celui de Filament : mêmes champs, même action.
        $this->assertStringContainsString('wire:submit="authenticate"', $html);
    }

    public function test_the_login_still_uses_the_standard_filament_authentication(): void
    {
        $this->assertTrue(is_subclass_of(Login::class, \Filament\Pages\Auth\Login::class));
        $overridden = collect((new \ReflectionClass(Login::class))->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED))
            ->filter(fn ($m) => $m->class === Login::class)->map->getName()->sort()->values()->all();

        $this->assertSame(['getHeading', 'getSubHeading'], $overridden, 'seuls les libellés sont redéfinis : aucune logique d\'accès');
    }

    public function test_a_super_admin_signs_in_and_reaches_the_console(): void
    {
        $admin = $this->superAdmin();

        Livewire::test(Login::class)->fillForm(['email' => $admin->email, 'password' => 'Un-Mot-De-Passe-12'])->call('authenticate')->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_wrong_credentials_and_non_platform_accounts_are_refused(): void
    {
        $admin = $this->superAdmin();
        Livewire::test(Login::class)->fillForm(['email' => $admin->email, 'password' => 'mauvais'])->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();

        // Un master de tenant a des identifiants valides mais aucun accès à la plateforme.
        $tenant = Tenant::create(['name' => 'Société Test']);
        $master = TenantContext::runAs($tenant->id, function () {
            $user = User::factory()->create(['password' => bcrypt('Un-Mot-De-Passe-12')]);
            $user->assignRole('master');

            return $user;
        });
        Livewire::test(Login::class)->fillForm(['email' => $master->email, 'password' => 'Un-Mot-De-Passe-12'])->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();
    }

    public function test_the_brand_panel_never_appears_inside_the_console(): void
    {
        config(['security.authentication.2fa_enabled' => false]); // la double authentification est testée ailleurs
        $this->actingAs($this->superAdmin());

        $html = $this->get('/platform')->assertOk()->getContent();

        $this->assertStringNotContainsString('class="pf-aside"', $html, 'le panneau de connexion ne doit pas s\'afficher une fois connecté');
        $this->assertStringContainsString('Tableau de bord', $html);
    }

    public function test_the_login_page_stays_usable_on_small_screens(): void
    {
        $css = file_get_contents(public_path('css/filament-auth.css'));

        // Le panneau de marque n'existe qu'à partir de 1024 px ; en dessous, seule la carte est affichée.
        $this->assertMatchesRegularExpression('/\.pf-aside\s*\{\s*display:\s*none;/', $css);
        $this->assertStringContainsString('@media (min-width: 1024px)', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
    }
}
