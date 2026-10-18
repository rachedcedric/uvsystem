<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Livewire\Mobile\Admin\Import;
use App\Livewire\Mobile\Crud;
use App\Livewire\Mobile\Subscription\Show;
use App\Models\Agent;
use App\Models\SubscriptionNotificationLog;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Mobile\DataImporter;
use App\Services\Subscriptions\SubscriptionManager;
use App\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel;

/** Application réelle des droits côté serveur (API, /m, Livewire) et isolation des abonnements entre tenants. */
class SubscriptionTenantAccessTest extends SubscriptionTestCase
{
    private function manager(): SubscriptionManager
    {
        return app(SubscriptionManager::class);
    }

    private function tenantOn(string $plan, array $planOverrides = []): Tenant
    {
        $tenant = $this->tenant();
        if ($planOverrides) {
            $this->plan($plan)->update($planOverrides);
        }
        $this->manager()->activate($tenant, $this->plan($plan), SubscriptionCycle::Monthly);

        return $tenant;
    }

    // ---------------------------------------------------------------- API

    public function test_the_api_refuses_an_agent_beyond_the_limit_and_creates_nothing(): void
    {
        $tenant = $this->tenantOn('essentiel', ['max_agents' => 1]);
        $master = $this->userIn($tenant);
        TenantContext::runAs($tenant->id, fn () => Agent::factory()->create());
        Sanctum::actingAs($master);

        $this->postJson('/api/agents', ['name' => 'Un de trop', 'phone' => '0701020304'])
            ->assertStatus(422)
            ->assertJsonPath('errors.subscription.0', fn ($m) => str_contains($m, 'Limite de 1 agents'));

        $this->assertSame(1, Agent::count());
    }

    public function test_the_api_refuses_every_write_but_still_serves_reads_when_expired(): void
    {
        $tenant = $this->tenantOn('pro');
        $master = $this->userIn($tenant);
        $agent = TenantContext::runAs($tenant->id, fn () => Agent::factory()->create());
        $this->travelToDate(now()->addMonths(3)->toDateTimeString());
        Sanctum::actingAs($master);

        $this->getJson('/api/agents')->assertOk();
        $this->postJson('/api/agents', ['name' => 'Nouveau', 'phone' => '0701020399'])->assertStatus(422);
        $this->putJson("/api/agents/{$agent->id}", ['name' => 'Renommé'])->assertStatus(422);
        $this->deleteJson("/api/agents/{$agent->id}")->assertStatus(422);

        $this->assertSame(1, Agent::count());
        $this->assertNotSame('Renommé', Agent::find($agent->id)->name);
    }

    // ---------------------------------------------------------------- /m

    public function test_the_mobile_user_form_shows_the_limit_message_and_creates_no_account(): void
    {
        $tenant = $this->tenantOn('essentiel', ['max_users' => 1]);
        $master = $this->userIn($tenant);
        $this->actingAs($master);

        Livewire::test(Crud\Form::class, ['resource' => 'utilisateurs'])
            ->set('data.name', 'Nouveau')->set('data.email', 'nouveau@example.com')
            ->set('data.password', 'Un-Mot-De-Passe#Long1')->set('data.password_confirmation', 'Un-Mot-De-Passe#Long1')
            ->set('data.roles', ['comptable'])
            ->call('save')
            ->assertHasErrors('form');

        $this->assertSame(1, User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_the_mobile_import_depends_on_the_plan_features(): void
    {
        $essentiel = $this->tenantOn('essentiel');
        $this->actingAs($this->userIn($essentiel));
        Livewire::test(Import::class)->assertForbidden();

        $pro = $this->tenantOn('pro');
        $this->actingAs($this->userIn($pro));
        Livewire::test(Import::class)->assertSuccessful();
    }

    public function test_the_mobile_import_stops_at_the_agent_limit_and_reports_the_rows(): void
    {
        $tenant = $this->tenantOn('pro', ['max_agents' => 1]);
        $this->actingAs($this->userIn($tenant));

        $csv = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($csv, "nom,prenom,telephone\nAlpha,Un,0701000001\nBeta,Deux,0701000002\n");

        $result = app(DataImporter::class)->import('agents', $csv, Excel::CSV);

        $this->assertSame(1, $result['success']);
        $this->assertCount(1, $result['failures']);
        $this->assertStringContainsString('Limite', $result['failures'][0]['message']);
        $this->assertSame(1, Agent::count());
    }

    // ---------------------------------------------------------------- page d'abonnement mobile

    public function test_the_mobile_subscription_page_shows_only_the_own_tenant(): void
    {
        $a = $this->tenantOn('pro');
        $b = $this->tenantOn('essentiel');
        $this->manager()->changePlan($b, $this->plan('entreprise')); // plan distinctif chez B
        $this->actingAs($this->userIn($a, 'master'));

        Livewire::test(Show::class)->assertSuccessful()
            ->assertSee('Offre Pro')->assertSee('10')->assertSee('Mensuel')
            ->assertDontSee('Offre Entreprise');
    }

    public function test_only_master_and_admin_reach_the_mobile_subscription_page(): void
    {
        $tenant = $this->tenantOn('pro');

        foreach (['master', 'admin'] as $role) {
            $this->actingAs($this->userIn($tenant, $role));
            $this->get('/m/abonnement')->assertOk();
        }
        foreach (['comptable', 'commercial'] as $role) {
            $this->actingAs($this->userIn($tenant, $role));
            $this->get('/m/abonnement')->assertForbidden();
        }
    }

    public function test_the_mobile_subscription_page_requires_a_login(): void
    {
        $this->get('/m/abonnement')->assertRedirect();
    }

    public function test_the_mobile_subscription_page_stays_reachable_when_expired_and_says_so(): void
    {
        $tenant = $this->tenantOn('pro');
        $master = $this->userIn($tenant);
        $this->travelToDate(now()->addMonths(3)->toDateTimeString());
        $this->actingAs($master);

        $this->get('/m/abonnement')->assertOk()->assertSee('Consultation seule');
        // L'accès aux données elles-mêmes (lecture) continue.
        $this->get('/m')->assertOk();
    }

    public function test_a_suspended_tenant_still_cannot_log_in_or_reach_anything(): void
    {
        $tenant = $this->tenantOn('pro');
        $master = $this->userIn($tenant);
        $tenant->suspend();
        $this->actingAs($master);

        $this->get('/m/abonnement')->assertForbidden();
    }

    // ---------------------------------------------------------------- isolation

    public function test_a_tenant_user_never_sees_another_tenants_subscriptions_in_any_query(): void
    {
        $a = $this->tenantOn('pro');
        $b = $this->tenantOn('essentiel');
        $userA = $this->userIn($a);
        $this->actingAs($userA);

        $visible = TenantSubscription::query()->pluck('tenant_id')->unique()->all();
        $this->assertSame([$a->id], $visible);
        $this->assertSame(0, TenantSubscription::query()->where('tenant_id', $b->id)->count());
        $this->assertSame(0, SubscriptionPayment::count());
        $this->assertSame(0, SubscriptionNotificationLog::count());

        // Écrire dans le tenant B est refusé quoi qu'on fournisse.
        $foreign = new TenantSubscription(['subscription_plan_id' => $this->plan('pro')->id, 'cycle' => 'monthly', 'status' => 'active']);
        $foreign->forceFill(['tenant_id' => $b->id]);
        $this->assertThrows(fn () => $foreign->save(), \LogicException::class);
    }

    public function test_the_catalogue_is_global_but_read_only_for_tenants(): void
    {
        $tenant = $this->tenantOn('pro');
        $master = $this->userIn($tenant);
        $this->actingAs($master);

        $this->assertGreaterThanOrEqual(3, SubscriptionPlan::count());
        $this->assertFalse($master->can('create', SubscriptionPlan::class));
        $this->assertFalse($master->can('update', $this->plan('pro')));
    }
}
