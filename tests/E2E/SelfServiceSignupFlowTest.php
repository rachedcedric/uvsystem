<?php

namespace Tests\E2E;

use App\Enums\SubscriptionCycle;
use App\Enums\SubscriptionStatus;
use App\Livewire\Mobile\Login;
use App\Models\Agent;
use App\Models\PaymentMethod;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionSignup;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Notifications\Subscriptions\VerifySignupNotification;
use App\Services\Subscriptions\SubscriptionManager;
use App\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PARCOURS COMPLET, sans aucune intervention du super admin :
 *   landing -> « Commencer gratuitement » -> /offres -> choix de l'offre et du cycle -> /inscription?plan=…&cycle=…
 *   -> formulaire -> e-mail de confirmation -> création du tenant, du master et de l'essai -> connexion -> tableau de bord.
 * Le navigateur est simulé au plus près : les liens, champs cachés et jetons sont LUS dans le HTML renvoyé.
 */
class SelfServiceSignupFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TenantContext::setFallback(null);
        Notification::fake();
        config(['subscriptions.signup.min_seconds_to_submit' => 0, 'subscriptions.require_subscription' => true]);
    }

    /** @return list<string> valeurs href de la page, décodées */
    private function hrefs(TestResponse $response): array
    {
        preg_match_all('/href="([^"]+)"/', $response->getContent(), $m);

        return array_map(fn ($h) => html_entity_decode($h), $m[1]);
    }

    private function hiddenValue(TestResponse $response, string $name): string
    {
        preg_match('/name="'.$name.'" value="([^"]*)"/', $response->getContent(), $m);

        return html_entity_decode($m[1] ?? '');
    }

    private function link(): string
    {
        $url = null;
        Notification::assertSentOnDemand(VerifySignupNotification::class, function ($n) use (&$url) {
            $url = $n->url;

            return true;
        });

        return $url;
    }

    private function prospect(int $i = 1, array $overrides = []): array
    {
        return $overrides + [
            'company_name' => "Entreprise Prospect {$i}", 'name' => "Gérant {$i}", 'email' => "gerant{$i}@prospect.example",
            'phone' => sprintf('07%08d', 1000 + $i), 'password' => 'Un-Mot-De-Passe-12', 'password_confirmation' => 'Un-Mot-De-Passe-12', 'accept_terms' => '1',
        ];
    }

    // ================================================================ le parcours complet

    public function test_a_prospect_goes_from_the_landing_page_to_the_tenant_dashboard_without_any_super_admin(): void
    {
        $this->assertSame(0, User::withoutGlobalScopes()->count() - User::withoutGlobalScopes()->whereNotNull('tenant_id')->count(), 'aucun compte de plateforme n\'existe');
        $this->assertSame(0, User::role('super_admin')->count());

        // 1. Landing page : le bouton « Commencer gratuitement » mène aux offres.
        $landing = $this->get('/')->assertOk()->assertSee('Commencer gratuitement');
        $this->assertContains('/offres', $this->hrefs($landing), 'la landing page doit renvoyer vers /offres');

        // 2. /offres : choix du cycle puis de l'offre ; chaque bouton renvoie VERS l'inscription avec plan et cycle.
        $offers = $this->get('/offres')->assertOk();
        $yearlyLink = collect($this->hrefs($offers))->first(fn ($h) => str_contains($h, 'cycle=yearly') && str_contains($h, '/offres'));
        $this->assertNotNull($yearlyLink, 'le sélecteur Annuel existe');

        $yearly = $this->get($yearlyLink)->assertOk()->assertSee('300 000 FCFA')->assertSee('/ an');
        $signupLink = collect($this->hrefs($yearly))->first(fn ($h) => str_contains($h, '/inscription') && str_contains($h, 'plan=pro'));
        $this->assertNotNull($signupLink);
        $this->assertStringContainsString('cycle=yearly', $signupLink);
        $this->assertStringStartsWith(url('/inscription'), $signupLink);

        // 3. /inscription?plan=pro&cycle=yearly : le formulaire récapitule l'offre lue en base.
        $form = $this->get($signupLink)->assertOk()->assertSee('Pro')->assertSee('300 000 FCFA')->assertSee('Annuel');
        $this->assertSame('pro', $this->hiddenValue($form, 'plan'));
        $this->assertSame('yearly', $this->hiddenValue($form, 'cycle'));

        // 4. Envoi du formulaire (champs cachés repris tels que le navigateur les renvoie) : RIEN n'est encore créé.
        $tenantsBefore = Tenant::count();
        $this->post('/inscription', $this->prospect(1, ['plan' => $this->hiddenValue($form, 'plan'), 'cycle' => $this->hiddenValue($form, 'cycle'), 'token' => $this->hiddenValue($form, 'token')]))
            ->assertRedirect(route('inscription.envoye'));
        $this->get(route('inscription.envoye'))->assertOk()->assertSee('Vérifiez votre boîte mail');
        $this->assertSame($tenantsBefore, Tenant::count());

        // 5. L'e-mail : un GET affiche la confirmation, le POST crée tout.
        $confirmPage = $this->get($this->link())->assertOk()->assertSee('Confirmer');
        $this->post($this->link())->assertRedirect(route('m.login'));

        // 6. Ce qui a été créé.
        $tenant = Tenant::where('contact_email', 'gerant1@prospect.example')->firstOrFail();
        $this->assertSame('Entreprise Prospect 1', $tenant->name);
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);

        $master = User::withoutGlobalScopes()->where('email', 'gerant1@prospect.example')->firstOrFail();
        $this->assertSame($tenant->id, $master->tenant_id);
        $this->assertSame(['master'], $master->getRoleNames()->all());
        $this->assertFalse($master->isSuperAdmin());
        $this->assertSame(1, User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(), 'un seul compte : le master');

        $subscription = app(SubscriptionManager::class)->current($tenant);
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertSame('pro', $subscription->plan->slug);
        $this->assertSame(SubscriptionCycle::Yearly, $subscription->cycle);
        $this->assertSame(300000, $subscription->applied_price);
        $this->assertSame(14, $subscription->daysRemaining());
        $this->assertTrue(TenantContext::runAs($tenant->id, fn () => PaymentMethod::count() > 0), 'données de référence du tenant créées');

        // 7. Connexion avec l'e-mail et le mot de passe choisis, puis tableau de bord du tenant.
        Livewire::test(Login::class)->set('email', 'gerant1@prospect.example')->set('password', 'Un-Mot-De-Passe-12')
            ->call('login')->assertHasNoErrors()->assertRedirect(route('m.home'));
        $this->get('/m')->assertOk();
        $this->get('/admin')->assertOk();
        $this->get('/m/abonnement')->assertOk()->assertSee('Offre Pro')->assertSee('Essai');
        $this->get('/platform')->assertForbidden(); // jamais le panneau plateforme

        // 8. Personne d'autre n'est intervenu : toujours aucun compte de plateforme.
        $this->assertSame(0, User::role('super_admin')->count());
    }

    public function test_the_prospect_can_start_a_monthly_trial_on_another_plan_the_same_way(): void
    {
        $page = $this->get('/inscription?plan=essentiel&cycle=monthly')->assertOk()->assertSee('Essentiel')->assertSee('15 000 FCFA');
        $this->post('/inscription', $this->prospect(2, ['plan' => 'essentiel', 'cycle' => 'monthly', 'token' => $this->hiddenValue($page, 'token')]));
        $this->post($this->link());

        $sub = app(SubscriptionManager::class)->current(Tenant::where('contact_email', 'gerant2@prospect.example')->firstOrFail());
        $this->assertSame('essentiel', $sub->plan->slug);
        $this->assertSame(SubscriptionCycle::Monthly, $sub->cycle);
        $this->assertSame(15000, $sub->applied_price);
    }

    public function test_many_companies_register_independently_and_never_see_each_other(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $count = 12;

        for ($i = 1; $i <= $count; $i++) {
            Notification::fake();
            $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"]);
            $plan = ['essentiel', 'pro', 'entreprise'][$i % 3];
            $cycle = $i % 2 ? 'monthly' : 'yearly';
            $page = $this->get("/inscription?plan={$plan}&cycle={$cycle}");
            $this->post('/inscription', $this->prospect(100 + $i, ['plan' => $plan, 'cycle' => $cycle, 'token' => $this->hiddenValue($page, 'token')]))->assertRedirect(route('inscription.envoye'));
            $this->post($this->link())->assertRedirect(route('m.login'));
        }

        $tenants = Tenant::whereIn('contact_email', collect(range(1, $count))->map(fn ($i) => 'gerant'.(100 + $i).'@prospect.example'))->get();
        $this->assertCount($count, $tenants);
        $this->assertCount($count, $tenants->pluck('slug')->unique());

        foreach ($tenants as $tenant) {
            $masters = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();
            $this->assertCount(1, $masters);
            $this->assertSame(['master'], $masters->first()->getRoleNames()->all());
            $this->assertSame(1, TenantSubscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNotNull('current_marker')->count());
        }
        $this->assertSame(0, User::role('super_admin')->count());

        // Isolation : le master du premier voit ses agents, jamais ceux du suivant.
        [$a, $b] = [$tenants[0], $tenants[1]];
        TenantContext::runAs($a->id, fn () => Agent::factory()->create(['name' => 'Agent de A']));
        TenantContext::runAs($b->id, fn () => Agent::factory()->create(['name' => 'Agent de B']));
        $this->actingAs(User::withoutGlobalScopes()->where('tenant_id', $a->id)->first());
        $this->assertSame(['Agent de A'], Agent::pluck('name')->all());
    }

    // ================================================================ le serveur décide, jamais le navigateur

    public function test_the_server_takes_plan_price_limits_and_trial_from_the_database_at_confirmation_time(): void
    {
        $page = $this->get('/inscription?plan=pro&cycle=yearly');
        $this->post('/inscription', $this->prospect(3, [
            'plan' => 'pro', 'cycle' => 'yearly', 'token' => $this->hiddenValue($page, 'token'),
            // valeurs que le navigateur essaie d'imposer
            'price' => 1, 'applied_price' => 1, 'yearly_price' => 1, 'trial_days' => 3650, 'max_users' => 9999, 'max_agents' => 9999, 'role' => 'super_admin', 'roles' => ['super_admin'], 'status' => 'active',
        ]));
        // Entre l'envoi du formulaire et la confirmation, l'offre est modifiée par la plateforme : la base fait foi.
        SubscriptionPlan::where('slug', 'pro')->update(['yearly_price' => 280000, 'trial_days' => 7, 'max_users' => 12]);

        $this->post($this->link());

        $tenant = Tenant::where('contact_email', 'gerant3@prospect.example')->firstOrFail();
        $sub = app(SubscriptionManager::class)->current($tenant);
        $this->assertSame(280000, $sub->applied_price);
        $this->assertSame(7, $sub->daysRemaining());
        $this->assertSame(12, $sub->plan->max_users);
        $this->assertSame(SubscriptionStatus::Trialing, $sub->status);
        $this->assertSame(['master'], User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()->getRoleNames()->all());
    }

    public function test_plan_and_cycle_in_the_url_are_only_hints_and_are_revalidated(): void
    {
        $this->get('/inscription?plan=inexistant&cycle=yearly')->assertRedirect(route('offres'));
        $this->get('/inscription')->assertRedirect(route('offres'));
        // Cycle absurde dans l'URL : retombe sur mensuel, jamais d'erreur ni de prix inventé.
        $this->get('/inscription?plan=pro&cycle=gratuit')->assertOk()->assertSee('30 000 FCFA')->assertSee('Mensuel');

        // Dans le POST, un cycle ou une offre forgés sont refusés.
        $page = $this->get('/inscription?plan=pro&cycle=monthly');
        $this->post('/inscription', $this->prospect(4, ['plan' => 'pro', 'cycle' => 'a-vie', 'token' => $this->hiddenValue($page, 'token')]))->assertSessionHasErrors('cycle');
        $page = $this->get('/inscription?plan=pro&cycle=monthly');
        $this->post('/inscription', $this->prospect(4, ['plan' => 'offre-fantome', 'cycle' => 'monthly', 'token' => $this->hiddenValue($page, 'token')]))->assertRedirect(route('offres'));
        $this->assertCount(0, SubscriptionSignup::all());

        // Une offre désactivée ou sans essai ne peut pas être souscrite.
        SubscriptionPlan::where('slug', 'essentiel')->update(['trial_days' => 0]);
        $this->get('/inscription?plan=essentiel')->assertOk();
        $page = $this->get('/inscription?plan=essentiel&cycle=monthly');
        $this->post('/inscription', $this->prospect(5, ['plan' => 'essentiel', 'cycle' => 'monthly', 'token' => $this->hiddenValue($page, 'token')]))->assertSessionHasErrors('plan');
        Notification::assertNothingSent();
    }

    // ================================================================ rôle : jamais super_admin

    public function test_the_signup_can_never_produce_a_super_admin_even_if_something_tries(): void
    {
        $page = $this->get('/inscription?plan=pro&cycle=monthly');
        $this->post('/inscription', $this->prospect(6, ['plan' => 'pro', 'cycle' => 'monthly', 'token' => $this->hiddenValue($page, 'token')]));
        $tenants = Tenant::count();
        $users = User::withoutGlobalScopes()->count();

        // Un composant (bogue, ajout futur…) qui tenterait d'élever le compte créé : la garde finale annule TOUT.
        app('events')->listen('eloquent.created: '.User::class, fn (User $user) => $user->assignRole('super_admin'));

        $this->post($this->link())->assertSessionHasErrors('token');

        $this->assertSame($tenants, Tenant::count(), 'aucun tenant créé');
        $this->assertSame($users, User::withoutGlobalScopes()->count(), 'aucun compte créé');
        $this->assertSame(0, User::role('super_admin')->count());
        $this->assertNull(SubscriptionSignup::first()->verified_at);
    }

    // ================================================================ transactionnel

    public function test_a_failure_at_any_step_creates_nothing_and_the_link_stays_usable(): void
    {
        $page = $this->get('/inscription?plan=pro&cycle=monthly');
        $this->post('/inscription', $this->prospect(7, ['plan' => 'pro', 'cycle' => 'monthly', 'token' => $this->hiddenValue($page, 'token')]));
        $link = $this->link();
        $before = [Tenant::count(), User::withoutGlobalScopes()->count(), TenantSubscription::withoutGlobalScopes()->count(), PaymentMethod::withoutGlobalScopes()->count()];

        // Panne APRÈS la création du tenant, de ses données de référence, de son abonnement ET de son master.
        app('events')->listen('eloquent.created: '.User::class, function () {
            throw new \RuntimeException('panne simulée');
        });
        $this->post($link)->assertSessionHasErrors('token');

        $this->assertSame($before, [Tenant::count(), User::withoutGlobalScopes()->count(), TenantSubscription::withoutGlobalScopes()->count(), PaymentMethod::withoutGlobalScopes()->count()],
            'tenant, compte, abonnement et données de référence : tout est annulé');
        $this->assertNull(SubscriptionSignup::first()->verified_at);

        // La panne disparaît : le MÊME lien aboutit, une seule fois.
        app('events')->forget('eloquent.created: '.User::class);
        $this->post($link)->assertRedirect(route('m.login'));
        $this->post($link)->assertRedirect(route('m.login'));
        $this->assertSame(1, Tenant::where('contact_email', 'gerant7@prospect.example')->count());
    }

    public function test_an_offer_that_loses_its_trial_between_the_two_steps_rolls_everything_back(): void
    {
        $page = $this->get('/inscription?plan=pro&cycle=monthly');
        $this->post('/inscription', $this->prospect(8, ['plan' => 'pro', 'cycle' => 'monthly', 'token' => $this->hiddenValue($page, 'token')]));
        SubscriptionPlan::where('slug', 'pro')->update(['trial_days' => 0]);
        $before = [Tenant::count(), User::withoutGlobalScopes()->count()];

        $this->post($this->link())->assertSessionHasErrors('plan');

        $this->assertSame($before, [Tenant::count(), User::withoutGlobalScopes()->count()]);
        $this->assertNull(SubscriptionSignup::first()->verified_at);

        SubscriptionPlan::where('slug', 'pro')->update(['trial_days' => 14]);
        $this->post($this->link())->assertRedirect(route('m.login'));
        $this->assertSame(1, Tenant::where('contact_email', 'gerant8@prospect.example')->count());
    }

    public function test_the_old_signup_address_still_leads_to_the_new_one_keeping_plan_and_cycle(): void
    {
        $this->get('/souscrire?plan=pro&cycle=yearly')->assertRedirect('/inscription?plan=pro&cycle=yearly');
    }

    public function test_the_signup_never_requires_nor_uses_a_platform_action(): void
    {
        // Aucune route de ce parcours n'est protégée par une authentification ni par un rôle de plateforme.
        foreach (['inscription', 'inscription.store', 'inscription.envoye', 'inscription.verifier', 'inscription.confirmer', 'offres'] as $name) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertEmpty(array_intersect($route->gatherMiddleware(), ['auth', 'auth:sanctum', 'staff', '2fa']), "$name ne doit exiger aucune connexion");
        }
    }
}
