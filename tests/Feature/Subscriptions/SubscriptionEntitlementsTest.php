<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Exceptions\FeatureNotAvailable;
use App\Exceptions\SubscriptionLimitReached;
use App\Exceptions\SubscriptionRestricted;
use App\Models\Agent;
use App\Models\Fournisseur;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionEntitlements;
use App\Services\Subscriptions\SubscriptionManager;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissing;
use Carbon\Carbon;

class SubscriptionEntitlementsTest extends SubscriptionTestCase
{
    private SubscriptionEntitlements $entitlements;

    private SubscriptionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(SubscriptionEntitlements::class);
        $this->manager = app(SubscriptionManager::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tenantOn(string $plan, array $planOverrides = []): Tenant
    {
        $tenant = $this->tenant();
        if ($planOverrides) {
            $this->plan($plan)->update($planOverrides);
        }
        $this->manager->activate($tenant, $this->plan($plan), SubscriptionCycle::Monthly);

        return $tenant;
    }

    private function agentIn(Tenant $tenant): Agent
    {
        return TenantContext::runAs($tenant->id, fn () => Agent::factory()->create());
    }

    public function test_user_limit_is_enforced_by_the_server_and_nothing_is_deleted(): void
    {
        $tenant = $this->tenantOn('essentiel'); // 3 utilisateurs
        foreach (range(1, 3) as $i) {
            $this->userIn($tenant, 'admin');
        }
        $this->assertSame(3, $this->entitlements->getUserUsage($tenant));
        $this->assertSame(0, $this->entitlements->remainingUsers($tenant));
        $this->assertFalse($this->entitlements->canCreateUser($tenant));

        $this->expectException(SubscriptionLimitReached::class);
        try {
            $this->userIn($tenant, 'admin');
        } finally {
            $this->assertSame(3, $this->entitlements->getUserUsage($tenant), 'aucun compte créé');
        }
    }

    public function test_agent_limit_is_enforced_by_the_server(): void
    {
        $tenant = $this->tenantOn('essentiel', ['max_agents' => 2]);
        $this->agentIn($tenant);
        $this->agentIn($tenant);

        $this->assertSame(0, $this->entitlements->remainingAgents($tenant));
        $this->assertThrows(fn () => $this->agentIn($tenant), SubscriptionLimitReached::class);
        $this->assertSame(2, $this->entitlements->getAgentUsage($tenant));
    }

    public function test_the_limit_message_is_clear(): void
    {
        $tenant = $this->tenantOn('essentiel', ['max_agents' => 1]);
        $this->agentIn($tenant);

        $message = $this->entitlements->agentCreationRefusal($tenant);

        $this->assertStringContainsString('Limite de 1 agents', $message);
        $this->assertStringContainsString('Essentiel', $message);
        $this->assertStringContainsString('conservées', $message);
    }

    public function test_unlimited_plans_have_no_limit_and_zero_is_not_unlimited(): void
    {
        $tenant = $this->tenantOn('entreprise');
        foreach (range(1, 15) as $i) {
            $this->userIn($tenant, 'admin');
        }

        $this->assertNull($this->entitlements->getUserLimit($tenant));
        $this->assertNull($this->entitlements->remainingUsers($tenant));
        $this->assertTrue($this->entitlements->canCreateUser($tenant));
        $this->assertTrue(SubscriptionEntitlements::isUnlimited(null));
        $this->assertFalse(SubscriptionEntitlements::isUnlimited(0), '0 n\'est jamais « illimité »');
    }

    public function test_downgrade_never_deletes_existing_data_but_blocks_new_creations(): void
    {
        $tenant = $this->tenantOn('pro'); // 10 utilisateurs
        foreach (range(1, 8) as $i) {
            $this->userIn($tenant, 'admin');
        }

        $this->manager->changePlan($tenant, $this->plan('essentiel')); // 3 utilisateurs

        $this->assertSame(8, $this->entitlements->getUserUsage($tenant), 'les 8 comptes restent');
        $this->assertSame(3, $this->entitlements->getUserLimit($tenant));
        $this->assertSame(0, $this->entitlements->remainingUsers($tenant));
        $this->assertFalse($this->entitlements->canCreateUser($tenant));
        $this->assertThrows(fn () => $this->userIn($tenant, 'admin'), SubscriptionLimitReached::class);
        $this->assertSame(8, $this->entitlements->getUserUsage($tenant));
    }

    public function test_a_soft_deleted_agent_cannot_be_restored_beyond_the_limit(): void
    {
        $tenant = $this->tenantOn('essentiel', ['max_agents' => 1]);
        $old = $this->agentIn($tenant);
        TenantContext::runAs($tenant->id, fn () => $old->delete());
        $this->agentIn($tenant); // reprend la place

        $this->assertThrows(fn () => TenantContext::runAs($tenant->id, fn () => $old->restore()), SubscriptionLimitReached::class);
    }

    public function test_features_follow_the_plan(): void
    {
        $essentiel = $this->tenantOn('essentiel');
        $pro = $this->tenantOn('pro');

        $this->assertTrue($this->entitlements->hasFeature($essentiel, 'exports_pdf'));
        $this->assertFalse($this->entitlements->hasFeature($essentiel, 'scoring_agents'));
        $this->assertTrue($this->entitlements->hasFeature($pro, 'scoring_agents'));
        $this->assertThrows(fn () => $this->entitlements->assertFeature($essentiel, 'scoring_agents'), FeatureNotAvailable::class);
    }

    public function test_a_tenant_without_subscription_is_legacy_and_unrestricted(): void
    {
        $tenant = $this->tenant();

        $this->assertNull($this->entitlements->getUserLimit($tenant));
        $this->assertTrue($this->entitlements->hasFeature($tenant, 'scoring_agents'));
        $this->userIn($tenant);
        $this->assertTrue($this->entitlements->canCreateUser($tenant));
    }

    public function test_entitlements_without_argument_use_the_current_tenant_only(): void
    {
        $a = $this->tenantOn('essentiel', ['max_agents' => 1]);
        $b = $this->tenantOn('pro');
        $this->agentIn($a);
        $this->agentIn($b);
        $this->agentIn($b);

        TenantContext::runAs($a->id, function () {
            $this->assertSame(1, $this->entitlements->getAgentUsage());
            $this->assertSame(1, $this->entitlements->getAgentLimit());
        });
        TenantContext::runAs($b->id, fn () => $this->assertSame(2, $this->entitlements->getAgentUsage()));

        $this->assertThrows(fn () => $this->entitlements->getAgentUsage(), TenantContextMissing::class);
    }

    // ---------------------------------------------------------------- expiration : politique centralisée

    public function test_an_expired_tenant_can_read_but_cannot_write_business_data(): void
    {
        $this->travelToDate('2026-10-10 10:00:00');
        $tenant = $this->tenantOn('pro');
        $user = $this->userIn($tenant);
        $agent = $this->agentIn($tenant);
        $this->travelToDate('2027-01-01 10:00:00'); // période échue

        $this->actingAs($user);

        // Lecture : intacte.
        $this->assertSame($agent->id, Agent::find($agent->id)->id);
        $this->assertSame(1, Agent::count());

        // Écritures métier : refusées par le modèle (création, modification, suppression).
        $this->assertThrows(fn () => Agent::factory()->create(), SubscriptionRestricted::class);
        $this->assertThrows(fn () => Fournisseur::factory()->create(), SubscriptionRestricted::class);
        $this->assertThrows(fn () => Agent::find($agent->id)->update(['name' => 'Changé']), SubscriptionRestricted::class);
        $this->assertThrows(fn () => Agent::find($agent->id)->delete(), SubscriptionRestricted::class);
        $this->assertNotSame('Changé', Agent::find($agent->id)->name);

        // Rien n'est supprimé, le compte reste utilisable (2FA, mot de passe…), mais ne peut pas créer d'autres comptes.
        $user->update(['name' => 'Nouveau nom']);
        $this->assertSame('Nouveau nom', User::find($user->id)->name);
        $this->assertThrows(fn () => $this->userIn($tenant), SubscriptionRestricted::class);
    }

    public function test_reactivation_by_the_platform_restores_writes(): void
    {
        $this->travelToDate('2026-10-10 10:00:00');
        $tenant = $this->tenantOn('pro');
        $user = $this->userIn($tenant);
        $this->travelToDate('2027-01-01 10:00:00');
        $this->actingAs($user);
        $this->assertThrows(fn () => Agent::factory()->create(), SubscriptionRestricted::class);

        $this->manager->renew($tenant, 'REF');

        $this->assertNotNull(Agent::factory()->create());
    }

    public function test_trusted_server_code_is_not_restricted_but_other_tenants_are_unaffected(): void
    {
        $this->travelToDate('2026-10-10 10:00:00');
        $expired = $this->tenantOn('pro');
        $healthy = $this->tenantOn('pro');
        $healthyUser = $this->userIn($healthy);
        $this->travelToDate('2026-10-10 10:00:00');
        $this->manager->activate($healthy, $this->plan('pro'), SubscriptionCycle::Yearly);
        $this->travelToDate('2027-01-01 10:00:00');

        // Code de plateforme / commande (runAs explicite) : l'écriture générique n'est pas bridée…
        $this->assertNotNull(TenantContext::runAs($expired->id, fn () => Fournisseur::factory()->create()));
        // …mais la CRÉATION d'agents/utilisateurs reste refusée pour tout point d'entrée (import, commande, service).
        $this->assertThrows(fn () => $this->agentIn($expired), SubscriptionRestricted::class);
        // Un autre tenant à jour n'est pas touché.
        $this->actingAs($healthyUser);
        $this->assertNotNull(Agent::factory()->create());
    }

    public function test_a_cancelled_subscription_works_until_the_paid_period_ends(): void
    {
        $this->travelToDate('2026-10-10 10:00:00');
        $tenant = $this->tenantOn('pro');
        $user = $this->userIn($tenant);
        $this->manager->cancel($tenant);
        $this->actingAs($user);

        $this->assertNotNull(Agent::factory()->create()); // période payée en cours

        $this->travelToDate('2026-11-11 10:00:00');
        $this->assertThrows(fn () => Agent::factory()->create(), SubscriptionRestricted::class);
    }

    public function test_a_suspended_subscription_is_restricted_and_a_suspended_tenant_is_blocked(): void
    {
        $tenant = $this->tenantOn('pro');
        $user = $this->userIn($tenant);
        $this->manager->suspend($tenant);
        $this->actingAs($user);
        $this->assertThrows(fn () => Agent::factory()->create(), SubscriptionRestricted::class);

        $this->manager->reactivate($tenant);
        $this->assertNotNull(Agent::factory()->create());

        $tenant->suspend();
        $this->assertFalse($tenant->fresh()->isActive());
    }
}
