<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionManager;
use App\Tenancy\TenantContext;

class SubscriptionPublicTest extends SubscriptionTestCase
{
    private function payload(array $overrides = [], string $plan = 'pro', string $cycle = 'monthly'): array
    {
        $page = $this->get("/souscrire?plan={$plan}&cycle={$cycle}");
        $token = session('signup_token');

        return $overrides + [
            'plan' => $plan, 'cycle' => $cycle, 'token' => $token,
            'company_name' => 'Boutique Soleil', 'name' => 'Awa Koné', 'email' => 'awa@example.com', 'phone' => '0701020304',
            'password' => 'Un-Mot-De-Passe-12', 'password_confirmation' => 'Un-Mot-De-Passe-12', 'accept_terms' => '1',
        ];
    }

    // ---------------------------------------------------------------- /offres

    public function test_the_pricing_page_shows_only_active_plans_with_database_prices(): void
    {
        $this->plan('essentiel')->update(['is_active' => false]);
        $this->plan('pro')->update(['monthly_price' => 31000]);

        $this->get('/offres')->assertOk()
            ->assertSee('Pro')->assertSee('31 000 FCFA')->assertSee('/ mois')
            ->assertSee('Entreprise')->assertSee('Agents illimités')->assertSee('Recommandée')
            ->assertDontSee('15 000 FCFA');
    }

    public function test_the_pricing_page_switches_between_monthly_and_yearly(): void
    {
        $this->get('/offres?cycle=yearly')->assertOk()
            ->assertSee('150 000 FCFA')->assertSee('300 000 FCFA')->assertSee('600 000 FCFA')->assertSee('/ an');
        $this->get('/offres?cycle=monthly')->assertOk()->assertSee('15 000 FCFA')->assertSee('/ mois');
        // Valeur inconnue : retour au mensuel, jamais d'erreur.
        $this->get('/offres?cycle=hacked')->assertOk()->assertSee('15 000 FCFA');
    }

    public function test_the_pricing_page_is_responsive_and_has_accessible_touch_targets(): void
    {
        $html = $this->get('/offres')->assertOk()->getContent();

        $this->assertStringContainsString('name="viewport"', $html);
        $this->assertStringContainsString('min-height:44px', $html);
        $this->assertStringNotContainsString('overflow-x:scroll', $html);
        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $html, 'pas d\'émojis comme icônes');
    }

    // ---------------------------------------------------------------- souscription

    public function test_a_valid_signup_creates_the_company_its_master_and_a_trial_recomputed_by_the_server(): void
    {
        $this->post('/souscrire', $this->payload())->assertRedirect(route('m.login'));

        $tenant = Tenant::where('contact_email', 'awa@example.com')->firstOrFail();
        $this->assertSame('Boutique Soleil', $tenant->name);

        $user = User::withoutGlobalScopes()->where('email', 'awa@example.com')->firstOrFail();
        $this->assertSame($tenant->id, $user->tenant_id);
        $this->assertTrue($user->hasRole('master'));
        $this->assertFalse($user->hasRole('super_admin'));
        $this->assertFalse($user->isSuperAdmin());

        $sub = app(SubscriptionManager::class)->current($tenant);
        $this->assertSame(SubscriptionStatus::Trialing, $sub->status);
        $this->assertSame('pro', $sub->plan->slug);
        $this->assertSame(14, $sub->daysRemaining());
        $this->assertSame(30000, $sub->applied_price);
        $this->assertNotNull($tenant->settings['terms_accepted_at']);
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
    }

    public function test_values_forged_by_the_browser_are_ignored(): void
    {
        $forged = $this->payload([
            'price' => 1, 'applied_price' => 1, 'monthly_price' => 1, 'trial_days' => 3650, 'max_users' => 9999, 'max_agents' => 9999,
            'role' => 'super_admin', 'roles' => ['super_admin'], 'status' => 'suspended', 'tenant_id' => 1, 'is_super_admin' => true,
            'subscription_status' => 'active', 'plan_id' => 999,
        ], plan: 'essentiel');

        $this->post('/souscrire', $forged)->assertRedirect(route('m.login'));

        $tenant = Tenant::where('contact_email', 'awa@example.com')->firstOrFail();
        $sub = app(SubscriptionManager::class)->current($tenant);
        $user = User::withoutGlobalScopes()->where('email', 'awa@example.com')->firstOrFail();

        $this->assertSame(15000, $sub->applied_price);
        $this->assertSame(14, $sub->daysRemaining());
        $this->assertSame(3, $sub->plan->max_users);
        $this->assertSame(SubscriptionStatus::Trialing, $sub->status);
        $this->assertNotSame(1, $tenant->id);
        $this->assertSame($tenant->id, $user->tenant_id);
        $this->assertSame(['master'], $user->getRoleNames()->all());
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
    }

    public function test_an_unknown_or_inactive_plan_is_refused(): void
    {
        $this->plan('pro')->update(['is_active' => false]);

        $this->get('/souscrire?plan=pro')->assertRedirect(route('offres'));
        $this->get('/souscrire?plan=inexistant')->assertRedirect(route('offres'));

        $payload = $this->payload(plan: 'essentiel');
        $payload['plan'] = 'pro'; // plan désactivé, forgé dans le POST
        $this->post('/souscrire', $payload)->assertRedirect(route('offres'));
        $this->assertNull(Tenant::where('contact_email', 'awa@example.com')->first());
    }

    public function test_a_duplicate_email_or_company_name_is_refused_and_creates_nothing(): void
    {
        $this->post('/souscrire', $this->payload())->assertRedirect(route('m.login'));
        $tenants = Tenant::count();

        $this->post('/souscrire', $this->payload(['company_name' => 'Autre Société']))->assertSessionHasErrors('email');
        $this->post('/souscrire', $this->payload(['email' => 'autre@example.com']))->assertSessionHasErrors('company_name');
        $this->post('/souscrire', $this->payload(['email' => 'AWA@Example.COM', 'company_name' => 'Troisième']))->assertSessionHasErrors('email');

        $this->assertSame($tenants, Tenant::count());
    }

    public function test_the_same_form_cannot_be_submitted_twice(): void
    {
        $payload = $this->payload();

        $this->post('/souscrire', $payload)->assertRedirect(route('m.login'));
        $before = [Tenant::count(), User::withoutGlobalScopes()->count()];

        $this->post('/souscrire', $payload)->assertRedirect(route('offres'))->assertSessionHasErrors('token');

        $this->assertSame($before, [Tenant::count(), User::withoutGlobalScopes()->count()]);
    }

    public function test_a_post_without_a_valid_token_is_refused(): void
    {
        $payload = $this->payload();
        $payload['token'] = 'forge';

        $this->post('/souscrire', $payload)->assertRedirect(route('offres'));
        $this->assertNull(Tenant::where('contact_email', 'awa@example.com')->first());
    }

    public function test_weak_passwords_and_missing_terms_are_refused(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class); // la limitation est testée à part
        foreach (['court1A', 'toutenminuscules12', 'SANSCHIFFRESMAJ', '12345678901234'] as $weak) {
            $this->post('/souscrire', $this->payload(['password' => $weak, 'password_confirmation' => $weak]))->assertSessionHasErrors('password');
        }
        $this->post('/souscrire', $this->payload(['accept_terms' => null]))->assertSessionHasErrors('accept_terms');
        $this->post('/souscrire', $this->payload(['password_confirmation' => 'différent-Mot-De-Passe-1']))->assertSessionHasErrors('password');

        $this->assertNull(Tenant::where('contact_email', 'awa@example.com')->first());
    }

    public function test_a_user_already_attached_to_a_company_cannot_create_a_second_one(): void
    {
        $tenant = $this->tenant();
        $user = $this->userIn($tenant);
        $this->actingAs($user);
        $count = Tenant::count();

        $this->get('/souscrire?plan=pro')->assertRedirect(route('offres'));
        $this->post('/souscrire', $this->payload())->assertRedirect(route('offres'));

        $this->assertSame($count, Tenant::count());
    }

    public function test_a_second_trial_is_impossible_with_the_same_email_even_for_a_new_company(): void
    {
        $this->post('/souscrire', $this->payload())->assertRedirect(route('m.login'));
        $tenant = Tenant::where('contact_email', 'awa@example.com')->firstOrFail();
        // L'entreprise est supprimée côté compte (utilisateur retiré) mais l'ancien essai reste enregistré sur le tenant.
        TenantContext::withoutTenancy(fn () => User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get()->each->forceDelete());

        $this->post('/souscrire', $this->payload(['company_name' => 'Nouvelle Boutique']))->assertSessionHasErrors('email');
    }

    public function test_the_signup_is_rate_limited(): void
    {
        $statuses = [];
        for ($i = 0; $i < 7; $i++) {
            $statuses[] = $this->post('/souscrire', $this->payload(['company_name' => '']))->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'la limitation de débit doit couper les tentatives répétées');
    }
}
