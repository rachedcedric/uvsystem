<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Home;
use App\Livewire\Mobile\HomeActivity;
use App\Livewire\Mobile\HomeAlerts;
use App\Livewire\Mobile\HomeChart;
use App\Livewire\Mobile\HomeTopAgents;
use App\Livewire\Mobile\Login;
use App\Livewire\Mobile\More;
use App\Models\Agent;
use App\Models\User;
use App\Support\Mobile\Fcfa;
use App\Support\Mobile\Nav;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(string $role = 'master'): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create(['password' => 'secret-pass']);
        $user->assignRole($role);

        return $user;
    }

    public function test_guest_is_redirected_to_mobile_login(): void
    {
        $this->get('/m')->assertRedirect(route('m.login'));
    }

    public function test_mobile_connectivity_check_has_a_public_health_endpoint(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_login_page_renders_without_the_tab_bar(): void
    {
        $this->get('/m/login')
            ->assertOk()
            ->assertSee('Bon retour.')
            ->assertDontSee('m-tabbar');
    }

    public function test_login_page_logs_out_an_authenticated_account_without_mobile_access(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/m/login')
            ->assertOk()
            ->assertSee('Bon retour.');

        $this->assertGuest();
    }

    public function test_user_can_log_in_and_stays_remembered(): void
    {
        $user = $this->adminUser();

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'secret-pass')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('m.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_wrong_password_shows_an_error(): void
    {
        $user = $this->adminUser();

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'mauvais')
            ->call('login')
            ->assertHasErrors(['email' => 'E-mail ou mot de passe incorrect.']);

        $this->assertGuest();
    }

    public function test_account_without_panel_role_cannot_log_in(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'secret-pass')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        $user = $this->adminUser();
        $component = Livewire::test(Login::class)->set('email', $user->email)->set('password', 'mauvais');

        foreach (range(1, 5) as $i) {
            $component->call('login');
        }

        $component->set('password', 'secret-pass')->call('login')->assertHasErrors('email');
        $this->assertGuest();
    }

    public function test_home_renders_for_a_signed_in_admin_with_tab_bar_and_hero(): void
    {
        $user = $this->adminUser();

        $this->actingAs($user)->get('/m')
            ->assertOk()
            ->assertSee('Solde UV disponible')
            ->assertSee('m-tabbar', false);

        Livewire::actingAs($user)->test(Home::class)->assertOk()->assertSee('Solde UV disponible');
    }

    public function test_lazy_home_sections_render(): void
    {
        $user = $this->adminUser();
        Agent::factory()->create(['status' => 'active', 'debt_limit' => 100000, 'current_debt' => 95000, 'name' => 'Awa Koné']);

        $render = function (string $component) use ($user) {
            Livewire::withoutLazyLoading();

            return Livewire::actingAs($user)->test($component);
        };

        $render(HomeAlerts::class)->assertSee('Awa Koné')->assertSee('95 %');
        $render(HomeChart::class)->assertOk();
        $render(HomeActivity::class)->assertSee('Activité récente');
        $render(HomeTopAgents::class)->assertSee('Meilleurs agents du mois');
    }

    public function test_more_page_lists_full_version_screens_and_logout(): void
    {
        $user = $this->adminUser();
        Permission::firstOrCreate(['name' => 'view_uv_transactions', 'guard_name' => 'web']);
        $user->givePermissionTo('view_uv_transactions');

        Livewire::actingAs($user)->test(More::class)
            ->assertOk()
            ->assertSee($user->name)
            ->assertSee('Se déconnecter')
            ->assertSee('Opérations')
            ->assertDontSee('Ventes UV');
    }

    public function test_more_page_filters_navigation_without_accents(): void
    {
        $user = $this->adminUser();

        Livewire::actingAs($user)->test(More::class)
            ->set('search', 'securite')
            ->assertSee('Sécurité du compte')
            ->assertSee('1 résultat')
            ->assertDontSee('Mon abonnement')
            ->assertDontSee('Préférences');
    }

    public function test_logout_signs_the_user_out(): void
    {
        $user = $this->adminUser();

        $this->actingAs($user)->post(route('m.logout'))->assertRedirect(route('m.login'));
        $this->assertGuest();
    }

    public function test_connection_ping_is_light_and_never_starts_a_session(): void
    {
        // L'appli l'appelle avec l'adresse du site comme origine : l'ancien /api/health démarrait alors
        // une session complète (cookie + écriture en base) à chaque contrôle.
        $response = $this->withHeaders(['Referer' => config('app.url').'/m', 'Origin' => config('app.url')])
            ->get('/m-ping?_=123');

        $response->assertNoContent();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_accueil_is_always_the_centre_tab(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $labels = fn (User $u) => array_column(Nav::tabs($u), 'label');

        // Tous les onglets : Agents, Ventes, [Accueil], Caisse, Plus.
        $master = User::factory()->create();
        $master->assignRole('master');
        $this->assertSame(['Agents', 'Ventes', 'Accueil', 'Caisse', 'Plus'], $labels($master));

        // Un onglet masqué par les permissions ne décale pas Accueil vers un bord.
        $limited = User::factory()->create();
        $limited->givePermissionTo(['view_uv_transactions', 'view_caisse']);
        $this->assertSame(['Ventes', 'Accueil', 'Caisse', 'Plus'], $labels($limited));

        $minimal = User::factory()->create();
        $this->assertSame(['Accueil', 'Plus'], $labels($minimal));

        // La barre rendue respecte cet ordre.
        $html = $this->actingAs($master)->get('/m')->getContent();
        preg_match('/<nav class="m-tabbar".*?<\/nav>/s', $html, $nav);
        preg_match_all('/<span>(Agents|Ventes|Accueil|Caisse|Plus)<\/span>/', $nav[0], $found);
        $this->assertSame(['Agents', 'Ventes', 'Accueil', 'Caisse', 'Plus'], $found[1]);
    }

    public function test_fcfa_formatting(): void
    {
        $this->assertSame("1\u{00A0}250\u{00A0}000", Fcfa::number(1250000));
        $this->assertSame("1\u{00A0}250\u{00A0}000\u{00A0}FCFA", Fcfa::format(1250000));
        $this->assertSame("2\u{00A0}800\u{00A0}000", Fcfa::number(2800000));
        $this->assertSame("800\u{00A0}FCFA", Fcfa::format(800));
    }
}
