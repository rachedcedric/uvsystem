<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\SubscriptionSignup;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Notifications\Subscriptions\VerifySignupNotification;
use App\Services\Subscriptions\SubscriptionManager;
use App\Services\Subscriptions\TenantSignupService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/** Pages publiques /offres et /inscription (double confirmation e-mail + anti-abus d'essais). */
class SubscriptionPublicTest extends SubscriptionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['subscriptions.signup.min_seconds_to_submit' => 0]); // le piège de rapidité est testé à part
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fields(array $overrides = [], string $plan = 'pro', string $cycle = 'monthly'): array
    {
        $this->get("/inscription?plan={$plan}&cycle={$cycle}");

        return $overrides + [
            'plan' => $plan, 'cycle' => $cycle, 'token' => session('signup_token'),
            'company_name' => 'Boutique Soleil', 'name' => 'Awa Koné', 'email' => 'awa@example.com', 'phone' => '0701020304',
            'password' => 'Un-Mot-De-Passe-12', 'password_confirmation' => 'Un-Mot-De-Passe-12', 'accept_terms' => '1',
        ];
    }

    private function submit(array $overrides = [], string $plan = 'pro', string $cycle = 'monthly'): TestResponse
    {
        return $this->post('/inscription', $this->fields($overrides, $plan, $cycle));
    }

    /** Lien de confirmation envoyé par e-mail (dernière notification). */
    private function link(): string
    {
        $url = null;
        Notification::assertSentOnDemand(VerifySignupNotification::class, function ($notification) use (&$url) {
            $url = $notification->url;

            return true;
        });

        return $url;
    }

    private function confirm(): TestResponse
    {
        return $this->post($this->link());
    }

    private function tenantCount(): int
    {
        return Tenant::count();
    }

    // ---------------------------------------------------------------- /offres

    public function test_the_pricing_page_shows_only_active_plans_with_database_prices(): void
    {
        $this->plan('essentiel')->update(['is_active' => false]);
        $this->plan('pro')->update(['monthly_price' => 31000]);

        $this->get('/offres')->assertOk()->assertSee('Pro')->assertSee('31 000 FCFA')->assertSee('/ mois')
            ->assertSee('Entreprise')->assertSee('Agents illimités')->assertSee('Recommandée')->assertDontSee('15 000 FCFA');
    }

    public function test_the_pricing_page_switches_between_monthly_and_yearly(): void
    {
        $this->get('/offres?cycle=yearly')->assertOk()->assertSee('150 000 FCFA')->assertSee('300 000 FCFA')->assertSee('600 000 FCFA')->assertSee('/ an');
        $this->get('/offres?cycle=monthly')->assertOk()->assertSee('15 000 FCFA')->assertSee('/ mois');
        $this->get('/offres?cycle=hacked')->assertOk()->assertSee('15 000 FCFA');
    }

    public function test_the_pricing_page_is_responsive_and_has_accessible_touch_targets(): void
    {
        $html = $this->get('/offres')->assertOk()->getContent();

        $this->assertStringContainsString('name="viewport"', $html);
        $this->assertStringContainsString('min-height:44px', $html);
        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $html, 'pas d\'émojis comme icônes');
    }

    // ---------------------------------------------------------------- étape 1 : demande

    public function test_the_first_step_creates_nothing_but_a_pending_request_and_sends_the_link(): void
    {
        $tenants = $this->tenantCount();
        $users = User::withoutGlobalScopes()->count();

        $this->submit()->assertRedirect(route('inscription.envoye'));

        $this->assertSame($tenants, $this->tenantCount(), 'aucune entreprise avant la confirmation');
        $this->assertSame($users, User::withoutGlobalScopes()->count(), 'aucun compte avant la confirmation');
        $this->assertCount(1, SubscriptionSignup::all());
        $this->assertNull(SubscriptionSignup::first()->verified_at);
        Notification::assertSentOnDemand(VerifySignupNotification::class);
        $this->assertStringContainsString('/inscription/verifier/', $this->link());
    }

    public function test_the_pending_request_stores_no_clear_text_secret(): void
    {
        $this->submit();
        $signup = SubscriptionSignup::first();
        $token = basename(parse_url($this->link(), PHP_URL_PATH));

        $raw = DB::table('subscription_signups')->first();
        $this->assertStringNotContainsString('Un-Mot-De-Passe-12', json_encode($raw));
        $this->assertStringNotContainsString('Boutique Soleil', $raw->payload, 'charge utile chiffrée');
        $this->assertNotSame($token, $raw->token_hash);
        $this->assertSame(hash('sha256', $token), $raw->token_hash, 'seul le hash du lien est stocké');

        $payload = $signup->payloadData();
        $this->assertTrue(Hash::check('Un-Mot-De-Passe-12', $payload['password_hash']));
        $this->assertArrayNotHasKey('password', $payload);
    }

    public function test_the_email_is_proven_before_anything_is_created_and_a_get_never_creates(): void
    {
        $this->submit();
        $link = $this->link();
        $tenants = $this->tenantCount();

        $this->get($link)->assertOk()->assertSee('Confirmer'); // scanner de liens e-mail : ne consomme rien
        $this->get($link)->assertOk();

        $this->assertSame($tenants, $this->tenantCount());
        $this->assertNull(SubscriptionSignup::first()->verified_at);
    }

    public function test_confirming_creates_the_company_its_master_and_a_trial_recomputed_by_the_server(): void
    {
        $this->submit();

        $this->confirm()->assertRedirect(route('m.login'));

        $tenant = Tenant::where('contact_email', 'awa@example.com')->firstOrFail();
        $user = User::withoutGlobalScopes()->where('email', 'awa@example.com')->firstOrFail();
        $this->assertSame($tenant->id, $user->tenant_id);
        $this->assertSame(['master'], $user->getRoleNames()->all());
        $this->assertFalse($user->isSuperAdmin());
        $this->assertTrue(Hash::check('Un-Mot-De-Passe-12', $user->password), 'le mot de passe choisi fonctionne (jamais haché deux fois)');

        $sub = app(SubscriptionManager::class)->current($tenant);
        $this->assertSame(SubscriptionStatus::Trialing, $sub->status);
        $this->assertSame('pro', $sub->plan->slug);
        $this->assertSame(14, $sub->daysRemaining());
        $this->assertSame(30000, $sub->applied_price);
        $this->assertNotNull($tenant->settings['terms_accepted_at']);
        $this->assertNotNull($tenant->settings['email_verified_at']);

        $signup = SubscriptionSignup::first();
        $this->assertNotNull($signup->verified_at);
        $this->assertSame($tenant->id, $signup->tenant_id);
        $this->assertSame([], $signup->payloadData(), 'charge utile vidée après usage');
    }

    public function test_values_forged_by_the_browser_are_ignored(): void
    {
        $this->submit([
            'price' => 1, 'applied_price' => 1, 'monthly_price' => 1, 'trial_days' => 3650, 'max_users' => 9999, 'max_agents' => 9999,
            'role' => 'super_admin', 'roles' => ['super_admin'], 'status' => 'suspended', 'tenant_id' => 1, 'is_super_admin' => true,
            'subscription_status' => 'active', 'plan_id' => 999,
        ], plan: 'essentiel');
        $this->confirm();

        $tenant = Tenant::where('contact_email', 'awa@example.com')->firstOrFail();
        $sub = app(SubscriptionManager::class)->current($tenant);
        $user = User::withoutGlobalScopes()->where('email', 'awa@example.com')->firstOrFail();

        $this->assertSame(15000, $sub->applied_price);
        $this->assertSame(14, $sub->daysRemaining());
        $this->assertSame(3, $sub->plan->max_users);
        $this->assertSame(SubscriptionStatus::Trialing, $sub->status);
        $this->assertNotSame(1, $tenant->id);
        $this->assertSame(['master'], $user->getRoleNames()->all());
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
    }

    // ---------------------------------------------------------------- étape 2 : lien

    public function test_confirming_twice_is_idempotent_and_creates_nothing_more(): void
    {
        $this->submit();
        $link = $this->link();

        $this->post($link)->assertRedirect(route('m.login'))->assertSessionHas('status', fn ($m) => str_contains($m, 'est confirmée'));
        $counts = [$this->tenantCount(), User::withoutGlobalScopes()->count(), TenantSubscription::withoutGlobalScopes()->count()];

        $this->post($link)->assertRedirect(route('m.login'))->assertSessionHas('status', fn ($m) => str_contains($m, 'déjà créée'));
        $this->post($link)->assertRedirect(route('m.login'));

        $this->assertSame($counts, [$this->tenantCount(), User::withoutGlobalScopes()->count(), TenantSubscription::withoutGlobalScopes()->count()]);
    }

    public function test_an_expired_link_is_refused_and_creates_nothing(): void
    {
        $this->submit();
        $link = $this->link();
        $this->travelToDate(now()->addHours(25)->toDateTimeString());

        $this->post($link)->assertForbidden(); // la signature expire avec le lien
        $this->assertNull(Tenant::where('contact_email', 'awa@example.com')->first());
    }

    public function test_a_tampered_or_unsigned_link_is_refused(): void
    {
        $this->submit();
        $token = basename(parse_url($this->link(), PHP_URL_PATH));

        $this->post("/inscription/verifier/{$token}")->assertForbidden();
        $this->post($this->link().'x')->assertForbidden();
        $this->post(URL::temporarySignedRoute('inscription.verifier', now()->addHour(), ['token' => 'inconnu']))->assertRedirect(route('offres'));
        $this->assertNull(Tenant::where('contact_email', 'awa@example.com')->first());
    }

    public function test_a_plan_deactivated_between_the_two_steps_blocks_the_creation(): void
    {
        $this->submit();
        $this->plan('pro')->update(['is_active' => false]);

        $this->confirm()->assertRedirect(route('offres'));
        $this->assertNull(Tenant::where('contact_email', 'awa@example.com')->first());
    }

    public function test_conditions_are_rechecked_at_confirmation(): void
    {
        $this->submit();
        // Entre-temps quelqu'un prend le nom d'entreprise.
        Tenant::create(['name' => 'Boutique Soleil']);

        $this->confirm()->assertRedirect(route('offres'))->assertSessionHasErrors('company_name');
        $this->assertNull(Tenant::where('contact_email', 'awa@example.com')->first());
    }

    // ---------------------------------------------------------------- anti-abus

    public function test_a_second_trial_with_the_same_mailbox_in_another_spelling_is_refused(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->submit(['email' => 'jean.dupont@gmail.com', 'phone' => '0701000001']);
        $this->confirm();
        $tenants = $this->tenantCount();

        foreach (['jeandupont@gmail.com', 'jean.dupont+essai2@gmail.com', 'J.E.A.N.Dupont@googlemail.com', 'JEAN.DUPONT@GMAIL.COM'] as $variant) {
            $this->submit(['email' => $variant, 'company_name' => 'Autre '.md5($variant), 'phone' => '0709999999'])->assertSessionHasErrors('email');
        }

        $this->assertSame($tenants, $this->tenantCount());
    }

    public function test_the_same_phone_number_cannot_start_a_second_trial(): void
    {
        $this->submit();
        $this->confirm();

        $this->submit(['email' => 'autre@example.com', 'company_name' => 'Autre Boutique', 'phone' => '07 01 02 03 04'])->assertSessionHasErrors('email');
    }

    public function test_existing_accounts_tenants_and_company_names_are_refused(): void
    {
        $tenant = $this->tenant('Déjà Là');
        $existing = $this->userIn($tenant);

        $this->submit(['email' => strtoupper($existing->email), 'company_name' => 'Nouvelle A'])->assertSessionHasErrors('email');
        Tenant::query()->whereKey($tenant->id)->update(['contact_email' => 'contact@deja.example.com']);
        $this->submit(['email' => 'contact@deja.example.com', 'company_name' => 'Nouvelle B'])->assertSessionHasErrors('email');
        $this->submit(['email' => 'neuf@example.com', 'company_name' => $tenant->name])->assertSessionHasErrors('company_name');

        $this->assertCount(0, SubscriptionSignup::all());
    }

    public function test_disposable_email_domains_are_refused(): void
    {
        $this->submit(['email' => 'robot@mailinator.com'])->assertSessionHasErrors('email');
        $this->submit(['email' => 'robot@yopmail.com'])->assertSessionHasErrors('email');

        $this->assertCount(0, SubscriptionSignup::all());
    }

    public function test_the_number_of_verified_signups_per_ip_and_per_day_is_capped(): void
    {
        config(['subscriptions.signup.max_per_ip_per_day' => 1]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

        $this->submit();
        $this->confirm();
        $this->submit(['email' => 'second@example.com', 'company_name' => 'Seconde', 'phone' => '0708000000'])->assertSessionHasErrors('email');

        // Le lendemain, la limite est levée.
        $this->travelToDate(now()->addDay()->addMinute()->toDateTimeString());
        $this->submit(['email' => 'second@example.com', 'company_name' => 'Seconde', 'phone' => '0708000000'])->assertRedirect(route('inscription.envoye'));
    }

    public function test_a_filled_honeypot_creates_nothing(): void
    {
        $this->submit(['website' => 'http://spam.example'])->assertSessionHasErrors('website');

        $this->assertCount(0, SubscriptionSignup::all());
        Notification::assertNothingSent();
    }

    public function test_a_form_posted_too_fast_is_refused(): void
    {
        config(['subscriptions.signup.min_seconds_to_submit' => 3]);

        $this->submit()->assertRedirect(route('offres'))->assertSessionHasErrors('token');
        $this->assertCount(0, SubscriptionSignup::all());

        // Un humain qui prend son temps passe.
        $fields = $this->fields();
        $this->travelToDate(now()->addSeconds(10)->toDateTimeString());
        $this->post('/inscription', $fields)->assertRedirect(route('inscription.envoye'));
    }

    public function test_confirmation_mails_per_address_are_limited(): void
    {
        config(['subscriptions.signup.max_mails_per_email_per_hour' => 2]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->submit()->assertRedirect(route('inscription.envoye'));
        $this->submit()->assertRedirect(route('inscription.envoye'));
        $this->submit()->assertSessionHasErrors('email');

        $this->assertCount(1, SubscriptionSignup::all(), 'un seul lien valide à la fois par adresse');
    }

    public function test_a_new_request_replaces_the_previous_link_for_the_same_address(): void
    {
        $this->submit();
        $first = $this->link();
        Notification::fake();
        $this->submit();

        $this->post($first)->assertRedirect(route('offres')); // ancien lien invalidé
        $this->confirm()->assertRedirect(route('m.login'));
    }

    public function test_a_mail_failure_leaves_no_pending_request(): void
    {
        Notification::swap(new class
        {
            public function route(): self
            {
                return $this;
            }

            public function notify(): void
            {
                throw new \RuntimeException('SMTP indisponible');
            }
        });

        $this->submit()->assertSessionHasErrors('email');
        $this->assertCount(0, SubscriptionSignup::all());
    }

    public function test_submitting_the_same_form_twice_is_idempotent(): void
    {
        $fields = $this->fields();

        $this->post('/inscription', $fields)->assertRedirect(route('inscription.envoye'));
        $this->post('/inscription', $fields)->assertRedirect(route('inscription.envoye'));

        $this->assertCount(1, SubscriptionSignup::all());
        Notification::assertSentOnDemandTimes(VerifySignupNotification::class, 1);
    }

    public function test_a_post_with_a_forged_token_is_refused(): void
    {
        $fields = $this->fields();
        $fields['token'] = 'forge';

        $this->post('/inscription', $fields)->assertRedirect(route('offres'))->assertSessionHasErrors('token');
        $this->assertCount(0, SubscriptionSignup::all());
    }

    public function test_weak_passwords_and_missing_terms_are_refused(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (['court1A', 'toutenminuscules12', 'SANSCHIFFRESMAJ', '12345678901234'] as $weak) {
            $this->submit(['password' => $weak, 'password_confirmation' => $weak])->assertSessionHasErrors('password');
        }
        $this->submit(['accept_terms' => null])->assertSessionHasErrors('accept_terms');
        $this->submit(['password_confirmation' => 'différent-Mot-De-Passe-1'])->assertSessionHasErrors('password');

        $this->assertCount(0, SubscriptionSignup::all());
    }

    public function test_an_unknown_or_inactive_plan_is_refused(): void
    {
        $this->plan('pro')->update(['is_active' => false]);
        $this->get('/inscription?plan=pro')->assertRedirect(route('offres'));
        $this->get('/inscription?plan=inexistant')->assertRedirect(route('offres'));

        $fields = $this->fields(plan: 'essentiel');
        $fields['plan'] = 'pro'; // plan désactivé, forgé dans le POST
        $this->post('/inscription', $fields)->assertRedirect(route('offres'));
        $this->assertCount(0, SubscriptionSignup::all());
    }

    public function test_a_user_already_attached_to_a_company_cannot_create_a_second_one(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->userIn($tenant));
        $count = $this->tenantCount();

        $this->get('/inscription?plan=pro')->assertRedirect(route('offres'));
        $this->post('/inscription', ['plan' => 'pro'])->assertRedirect(route('offres'));

        $this->assertSame($count, $this->tenantCount());
        $this->assertCount(0, SubscriptionSignup::all());
    }

    public function test_the_signup_is_rate_limited_with_a_configurable_ceiling(): void
    {
        config(['subscriptions.signup.max_requests_per_ip_per_hour' => 3]);
        $statuses = [];
        for ($i = 0; $i < 7; $i++) {
            $statuses[] = $this->post('/inscription', ['company_name' => ''])->getStatusCode();
        }

        $this->assertContains(429, $statuses);
    }

    public function test_email_normalisation_rules(): void
    {
        $this->assertSame('jeandupont@gmail.com', TenantSignupService::normalizeEmail('Jean.Dupont+x@Gmail.com'));
        $this->assertSame('jeandupont@gmail.com', TenantSignupService::normalizeEmail('jean.dupont@googlemail.com'));
        $this->assertSame('jean.dupont@entreprise.ci', TenantSignupService::normalizeEmail('Jean.Dupont+promo@Entreprise.ci'), 'points conservés hors gmail');
        $this->assertSame('0701020304', TenantSignupService::normalizePhone('07 01-02.03 04'));
        $this->assertNull(TenantSignupService::normalizePhone(' '));
    }
}
