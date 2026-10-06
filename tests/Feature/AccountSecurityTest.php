<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG = 'Nouveau-Mot-De-Passe-42';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWith(string $role = 'master'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function fakeSession(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '10.0.0.9',
            'user_agent' => 'Mozilla/5.0 (Linux; Android 14; wv) Chrome/120 Mobile',
            'payload' => '', 'last_activity' => time(),
        ]);
    }

    // ---- mot de passe oublié ----------------------------------------------------------------------------

    public function test_the_reset_link_is_sent_without_revealing_whether_the_account_exists(): void
    {
        Notification::fake();
        $user = $this->userWith();

        $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'personne@example.com']);

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
        $this->assertSame(session('status'), session('status'));
        $known->assertSessionHas('status');
        $unknown->assertSessionHas('status', session('status'));
        $unknown->assertSessionDoesntHaveErrors();
    }

    public function test_a_valid_token_resets_the_password_and_cuts_every_access_but_keeps_2fa(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->userWith();
        $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_enabled' => true])->save();
        $user->createToken('telephone');
        $this->fakeSession($user, 'old-session-id');
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => self::STRONG, 'password_confirmation' => self::STRONG])
            ->assertRedirect('/m/login');

        $fresh = User::withoutGlobalScopes()->find($user->id);
        $this->assertTrue(Hash::check(self::STRONG, $fresh->password));
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session-id']);
        $this->assertTrue($fresh->hasTwoFactorEnabled(), 'Le lien e-mail ne doit jamais désactiver la 2FA.');

        // le jeton est à usage unique
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Autre-Mot-De-Passe-77', 'password_confirmation' => 'Autre-Mot-De-Passe-77'])
            ->assertSessionHasErrors('email');
    }

    public function test_weak_passwords_and_bad_tokens_are_refused(): void
    {
        $user = $this->userWith();
        $token = Password::broker()->createToken($user);
        $before = $user->password;

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'court', 'password_confirmation' => 'court'])
            ->assertSessionHasErrors('password');
        $this->post('/reset-password', ['token' => 'faux-jeton', 'email' => $user->email, 'password' => self::STRONG, 'password_confirmation' => self::STRONG])
            ->assertSessionHasErrors('email');

        $this->assertSame($before, User::withoutGlobalScopes()->find($user->id)->password);
    }

    // ---- appareils connectés -----------------------------------------------------------------------------

    public function test_the_device_list_shows_only_my_sessions_and_i_cannot_close_someone_elses(): void
    {
        config(['session.driver' => 'database']);
        $me = $this->userWith();
        $other = $this->userWith('commercial');
        $this->fakeSession($me, 'mine-other-device');
        $this->fakeSession($other, 'theirs');

        $this->actingAs($me)->get('/security/sessions')
            ->assertOk()->assertSee('Application MODELEC · Android')->assertSee('10.0.0.9');

        // IDOR : l'identifiant d'une session d'un autre compte ne permet rien
        $this->post('/security/sessions/revoke', ['session_id' => 'theirs'])->assertRedirect();
        $this->assertDatabaseHas('sessions', ['id' => 'theirs']);

        $this->post('/security/sessions/revoke', ['session_id' => 'mine-other-device'])->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['id' => 'mine-other-device']);
    }

    public function test_signing_out_everywhere_requires_the_password_and_keeps_only_this_session(): void
    {
        config(['session.driver' => 'database']);
        $me = $this->userWith();
        $me->createToken('telephone');
        $this->fakeSession($me, 'ailleurs');

        $this->actingAs($me)->post('/security/sessions/revoke-others', ['password' => 'mauvais'])->assertSessionHasErrors('password');
        $this->assertDatabaseHas('sessions', ['id' => 'ailleurs']);
        $this->assertSame(1, $me->tokens()->count());

        $this->post('/security/sessions/revoke-others', ['password' => 'password'])->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['id' => 'ailleurs']);
        $this->assertSame(0, $me->tokens()->count());
    }

    public function test_a_token_can_only_be_revoked_by_its_owner(): void
    {
        $me = $this->userWith();
        $other = $this->userWith('commercial');
        $mine = $me->createToken('a')->accessToken;
        $theirs = $other->createToken('b')->accessToken;

        $this->actingAs($me)->post("/security/tokens/{$theirs->id}/revoke")->assertRedirect();
        $this->assertSame(1, $other->tokens()->count());

        $this->post("/security/tokens/{$mine->id}/revoke")->assertRedirect();
        $this->assertSame(0, $me->tokens()->count());
    }

    // ---- changement de mot de passe -----------------------------------------------------------------------

    public function test_changing_the_password_needs_the_current_one_and_logs_other_devices_out(): void
    {
        config(['session.driver' => 'database']);
        $me = $this->userWith();
        $this->fakeSession($me, 'autre-appareil');
        $this->actingAs($me);

        $this->post('/security/password', ['current_password' => 'mauvais', 'password' => self::STRONG, 'password_confirmation' => self::STRONG])
            ->assertSessionHasErrors('current_password');
        $this->post('/security/password', ['current_password' => 'password', 'password' => 'faible', 'password_confirmation' => 'faible'])
            ->assertSessionHasErrors('password');
        $this->post('/security/password', ['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertSessionHasErrors('password');
        $this->assertDatabaseHas('sessions', ['id' => 'autre-appareil']);

        $this->post('/security/password', ['current_password' => 'password', 'password' => self::STRONG, 'password_confirmation' => self::STRONG])
            ->assertRedirect();

        $this->assertTrue(Hash::check(self::STRONG, $me->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'autre-appareil']);
    }

    // ---- suspension ----------------------------------------------------------------------------------------

    public function test_suspending_a_tenant_destroys_its_users_tokens_and_sessions(): void
    {
        config(['session.driver' => 'database']);
        TenantContext::setFallback(null);
        $tenant = Tenant::create(['name' => 'Société S']);
        $user = TenantContext::runAs($tenant->id, function () {
            $u = User::factory()->create();
            $u->assignRole('master');

            return $u;
        });
        $user->createToken('telephone');
        $this->fakeSession($user, 'session-de-S');

        $tenant->suspend();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'session-de-S']);
    }
}
