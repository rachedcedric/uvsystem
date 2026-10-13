<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\TwoFactorGate;
use App\Services\TwoFactorAuthService;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['security.authentication.2fa_enforce_from' => null]);
    }

    private function userWith(string $role, bool $twoFactor = false): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        if ($twoFactor) {
            $user->forceFill([
                'two_factor_secret' => (new Google2FA)->generateSecretKey(),
                'two_factor_enabled' => true,
                'two_factor_recovery_codes' => ['AAAA-BBBB-CCCC'],
            ])->save();
        }

        return $user->fresh();
    }

    private function otp(User $user): string
    {
        return (new Google2FA)->getCurrentOtp($user->two_factor_secret);
    }

    private function superAdmin(bool $twoFactor): User
    {
        $user = TenantContext::runAs(null, fn () => User::factory()->create());
        $user->assignRole(User::ROLE_SUPER_ADMIN);

        if ($twoFactor) {
            $user->forceFill(['two_factor_secret' => (new Google2FA)->generateSecretKey(), 'two_factor_enabled' => true])->save();
        }

        return $user->fresh();
    }

    // ---- le code est exigé quand la 2FA est activée --------------------------------------------------

    public function test_a_user_with_2fa_must_prove_the_second_factor_before_reaching_the_app(): void
    {
        $user = $this->userWith('commercial', twoFactor: true);

        $this->actingAs($user)->get('/m')->assertRedirect(route('2fa.challenge'));

        $this->post('/2fa/verify', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->get('/m')->assertRedirect(route('2fa.challenge'));

        $this->post('/2fa/verify', ['code' => $this->otp($user)])->assertRedirectContains('/m');
        $this->get('/m')->assertOk();
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = $this->userWith('commercial', twoFactor: true);

        $this->actingAs($user)->post('/2fa/verify-recovery', ['recovery_code' => 'aaaa-bbbb-cccc'])->assertRedirect();
        $this->assertSame([], $user->fresh()->two_factor_recovery_codes);

        session()->flush();
        $this->post('/2fa/verify-recovery', ['recovery_code' => 'AAAA-BBBB-CCCC'])->assertSessionHasErrors('recovery_code');
    }

    public function test_an_otp_cannot_be_replayed(): void
    {
        $user = $this->userWith('commercial', twoFactor: true);
        $code = $this->otp($user);
        $service = app(TwoFactorAuthService::class);

        $this->assertTrue($service->validateOtp($user, $code));
        $this->assertFalse($service->validateOtp($user, $code));
    }

    public function test_brute_force_on_the_code_is_throttled(): void
    {
        $user = $this->userWith('commercial', twoFactor: true);
        $this->actingAs($user);

        foreach (range(1, 5) as $i) {
            $this->post('/2fa/verify', ['code' => '00000'.$i])->assertSessionHasErrors('code');
        }

        // 6e essai : même avec le BON code, on est bloqué.
        $this->post('/2fa/verify', ['code' => $this->otp($user)])->assertSessionHasErrors('code');
        $this->get('/m')->assertRedirect(route('2fa.challenge'));
    }

    public function test_a_trusted_device_skips_the_code_until_the_2fa_changes(): void
    {
        $user = $this->userWith('commercial', twoFactor: true);

        $response = $this->actingAs($user)->post('/2fa/verify', ['code' => $this->otp($user), 'trust_device' => '1']);
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === TwoFactorGate::COOKIE);
        $this->assertNotNull($cookie);
        $token = CookieValuePrefix::remove($this->app['encrypter']->decrypt($cookie->getValue(), false));

        // Nouvelle session (le cookie de confiance est conservé par l'appareil) : pas de nouveau code.
        session()->flush();
        $this->withCookie(TwoFactorGate::COOKIE, $token)->get('/m')->assertOk();

        // Un autre compte ne peut pas réutiliser ce cookie.
        $other = $this->userWith('commercial', twoFactor: true);
        session()->flush();
        $this->actingAs($other)->withCookie(TwoFactorGate::COOKIE, $token)->get('/m')->assertRedirect(route('2fa.challenge'));

        // Réinitialiser la 2FA invalide tous les appareils de confiance.
        app(TwoFactorAuthService::class)->disable($user);
        $user->forceFill(['two_factor_secret' => (new Google2FA)->generateSecretKey(), 'two_factor_enabled' => true])->save();
        session()->flush();
        $this->actingAs($user->fresh())->withCookie(TwoFactorGate::COOKIE, $token)->get('/m')->assertRedirect(route('2fa.challenge'));
    }

    // ---- obligation par rôle, sans verrouiller personne par surprise -------------------------------------

    public function test_admins_only_get_a_reminder_before_the_enforcement_date(): void
    {
        $master = $this->userWith('master');

        $this->actingAs($master)->get('/m')->assertOk()->assertSee('Activer maintenant');

        config(['security.authentication.2fa_enforce_from' => now()->addDays(10)->toDateString()]);
        $this->actingAs($master)->get('/m')->assertOk()->assertSee('obligatoire dans');
    }

    public function test_after_the_enforcement_date_admins_must_enrol_but_other_roles_are_untouched(): void
    {
        config(['security.authentication.2fa_enforce_from' => now()->subDay()->toDateString()]);
        $master = $this->userWith('master');
        $commercial = $this->userWith('commercial');

        $this->actingAs($commercial)->get('/m')->assertOk();

        $this->actingAs($master)->get('/m')->assertRedirect(route('security.2fa'));
        // la page d'activation reste accessible (aucun verrouillage sans issue)
        $this->get('/security/2fa')->assertOk()->assertSee('Obligatoire pour votre rôle');
    }

    public function test_enrolling_enables_2fa_unlocks_access_and_shows_recovery_codes_once(): void
    {
        config(['security.authentication.2fa_enforce_from' => now()->subDay()->toDateString()]);
        $master = $this->userWith('master');
        $this->actingAs($master)->get('/security/2fa')->assertOk();

        $secret = session('2fa_enroll_secret');
        $this->assertNotEmpty($secret);

        $this->post('/security/2fa/confirm', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->post('/security/2fa/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertRedirect(route('security.2fa'));

        $this->assertTrue($master->fresh()->hasTwoFactorEnabled());
        $this->get('/security/2fa')->assertOk()->assertSee('codes de récupération');
        $this->get('/security/2fa')->assertOk()->assertDontSee('codes de récupération');
        $this->get('/m')->assertOk();
    }

    public function test_disabling_requires_the_password_and_is_refused_once_the_role_imposes_2fa(): void
    {
        $master = $this->userWith('master', twoFactor: true);
        $this->withSession([TwoFactorGate::SESSION_KEY => $master->id])->actingAs($master);

        $this->post('/security/2fa/disable', ['password' => 'mauvais'])->assertSessionHasErrors('password');
        $this->assertTrue($master->fresh()->hasTwoFactorEnabled());

        config(['security.authentication.2fa_enforce_from' => now()->subDay()->toDateString()]);
        $this->post('/security/2fa/disable', ['password' => self::PASSWORD])->assertForbidden();
        $this->assertTrue($master->fresh()->hasTwoFactorEnabled());

        config(['security.authentication.2fa_enforce_from' => null]);
        $this->post('/security/2fa/disable', ['password' => self::PASSWORD])->assertRedirect(route('security.2fa'));
        $this->assertFalse($master->fresh()->hasTwoFactorEnabled());
    }

    // ---- super admin : toujours en 2FA -------------------------------------------------------------------

    public function test_the_platform_super_admin_must_always_use_2fa(): void
    {
        $this->actingAs($this->superAdmin(twoFactor: false))->get('/platform')->assertRedirect(route('security.2fa'));

        $super = $this->superAdmin(twoFactor: true);
        $this->actingAs($super)->get('/platform')->assertRedirect(route('2fa.challenge'));

        $this->post('/2fa/verify', ['code' => $this->otp($super)])->assertRedirect();
        $this->get('/platform')->assertOk();

        $this->assertFalse(app(TwoFactorGate::class)->mayDisable($super));
    }

    // ---- API et secours -----------------------------------------------------------------------------------

    public function test_api_login_requires_a_valid_code_for_accounts_with_2fa(): void
    {
        $user = $this->userWith('master', twoFactor: true);
        $credentials = ['email' => $user->email, 'password' => self::PASSWORD];

        $this->postJson('/api/auth/login', $credentials)->assertStatus(422)->assertJsonPath('otp_required', true);
        $this->postJson('/api/auth/login', $credentials + ['otp' => '000000'])->assertStatus(422);
        $this->postJson('/api/auth/login', $credentials + ['otp' => $this->otp($user)])->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_the_platform_can_reset_a_locked_out_users_2fa(): void
    {
        $user = $this->userWith('master', twoFactor: true);

        $this->artisan('platform:2fa-reset', ['email' => $user->email])->assertSuccessful();

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->artisan('platform:2fa-reset', ['email' => 'inconnu@example.com'])->assertFailed();
    }

    public function test_the_feature_flag_turns_everything_off(): void
    {
        config(['security.authentication.2fa_enabled' => false]);
        $user = $this->userWith('master', twoFactor: true);

        $this->actingAs($user)->get('/m')->assertOk();
    }
}
