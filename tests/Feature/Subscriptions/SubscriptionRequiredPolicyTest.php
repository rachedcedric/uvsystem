<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Exceptions\SubscriptionRestricted;
use App\Models\Agent;
use App\Models\Fournisseur;
use App\Models\Tenant;
use App\Services\Subscriptions\SubscriptionEntitlements;
use App\Services\Subscriptions\SubscriptionManager;
use App\Support\Subscriptions\ExportGate;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantProvisioner;

/** Politique SUBSCRIPTION_REQUIRED : fail closed par défaut ; « sans abonnement » n'est plus un état permissif. */
class SubscriptionRequiredPolicyTest extends SubscriptionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['subscriptions.require_subscription' => true]); // phpunit.xml garde l'ancien mode pour les tests historiques
    }

    private function bootstrapUser(Tenant $tenant): \App\Models\User
    {
        return \App\Services\Subscriptions\SubscriptionAccessPolicy::withoutRestrictions(fn () => $this->userIn($tenant));
    }

    public function test_the_shipped_default_is_fail_closed(): void
    {
        // Valeur lue dans config/subscriptions.php SANS la variable d'environnement posée par phpunit.xml.
        $saved = [getenv('SUBSCRIPTION_REQUIRED'), $_ENV['SUBSCRIPTION_REQUIRED'] ?? null, $_SERVER['SUBSCRIPTION_REQUIRED'] ?? null];
        putenv('SUBSCRIPTION_REQUIRED');
        unset($_ENV['SUBSCRIPTION_REQUIRED'], $_SERVER['SUBSCRIPTION_REQUIRED']);
        \Illuminate\Support\Env::disablePutenv();
        try {
            $default = (require config_path('subscriptions.php'))['require_subscription'];
        } finally {
            if ($saved[0] !== false) {
                putenv("SUBSCRIPTION_REQUIRED={$saved[0]}");
            }
            $_ENV['SUBSCRIPTION_REQUIRED'] = $saved[1] ?? 'false';
            $_SERVER['SUBSCRIPTION_REQUIRED'] = $saved[2] ?? 'false';
        }

        $this->assertTrue($default, 'sans variable d\'environnement, un tenant sans abonnement doit être restreint');
    }

    public function test_a_tenant_without_subscription_is_restricted_and_cannot_write_or_create(): void
    {
        $tenant = $this->tenant();
        $user = $this->bootstrapUser($tenant); // amorçage : le premier compte se crée sans abonnement
        $this->actingAs($user);

        $this->assertThrows(fn () => Fournisseur::factory()->create(), SubscriptionRestricted::class);
        $this->assertThrows(fn () => Agent::factory()->create(), SubscriptionRestricted::class);
        $this->assertFalse(app(SubscriptionEntitlements::class)->canCreateUser($tenant));
        $this->assertFalse(app(SubscriptionEntitlements::class)->canCreateAgent($tenant));
    }

    public function test_reading_and_data_recovery_exports_stay_possible_without_subscription(): void
    {
        $tenant = $this->tenant();
        $user = $this->bootstrapUser($tenant);
        \App\Services\Subscriptions\SubscriptionAccessPolicy::withoutRestrictions(fn () => TenantContext::runAs($tenant->id, fn () => Agent::factory()->create()));
        $this->actingAs($user);
        $e = app(SubscriptionEntitlements::class);

        $this->assertSame(1, Agent::count(), 'lecture intacte');
        $this->assertTrue($e->hasFeature($tenant, 'exports_pdf'));
        $this->assertTrue($e->hasFeature($tenant, 'exports_excel'));
        ExportGate::require('exports_pdf'); // ne lève pas
        // Tout le reste est fermé.
        foreach (['scoring_agents', 'import_donnees', 'statistiques_avancees', 'exports_avances', 'notifications_push', 'sauvegardes_dediees', 'rapports_avances'] as $feature) {
            $this->assertFalse($e->hasFeature($tenant, $feature), $feature);
        }
        $this->assertThrows(fn () => ExportGate::require('exports_avances'), \Symfony\Component\HttpKernel\Exception\HttpException::class);
    }

    public function test_the_subscription_page_stays_reachable_and_says_there_is_no_subscription(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->bootstrapUser($tenant));

        $this->get('/m/abonnement')->assertOk()->assertSee('Aucun abonnement enregistré');
    }

    public function test_assigning_a_subscription_lifts_the_restriction(): void
    {
        $tenant = $this->tenant();
        $user = $this->bootstrapUser($tenant);
        $this->actingAs($user);
        $this->assertThrows(fn () => Agent::factory()->create(), SubscriptionRestricted::class);

        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);

        $this->assertNotNull(Agent::factory()->create());
    }

    public function test_the_old_legacy_mode_is_only_reachable_by_explicit_configuration(): void
    {
        $tenant = $this->tenant();
        $user = $this->bootstrapUser($tenant);
        $this->actingAs($user);

        config(['subscriptions.require_subscription' => false]);
        \App\Services\Subscriptions\SubscriptionAccessPolicy::flush();

        $this->assertNotNull(Agent::factory()->create());
    }

    public function test_the_provisioner_attaches_the_subscription_before_the_first_account(): void
    {
        $manager = app(SubscriptionManager::class);
        $tenant = app(TenantProvisioner::class)->provision(
            ['name' => 'Nouvelle '.uniqid()],
            ['name' => 'Admin', 'email' => 'a'.uniqid().'@example.com', 'password' => 'un-mot-de-passe-long'],
            fn (Tenant $t) => $manager->startTrial($t, $this->plan('essentiel'), SubscriptionCycle::Monthly),
        );

        $this->assertNotNull($manager->current($tenant));
        $this->assertFalse(\App\Services\Subscriptions\SubscriptionAccessPolicy::isRestricted($tenant));
        $this->assertSame(1, \App\Models\User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_a_tenant_provisioned_without_subscription_has_its_master_but_stays_restricted(): void
    {
        $tenant = app(TenantProvisioner::class)->provision(['name' => 'Technique '.uniqid()], ['name' => 'Admin', 'email' => 'b'.uniqid().'@example.com', 'password' => 'un-mot-de-passe-long']);

        $this->assertNull(app(SubscriptionManager::class)->current($tenant));
        $this->assertTrue(\App\Services\Subscriptions\SubscriptionAccessPolicy::isRestricted($tenant));
        $this->assertSame(1, \App\Models\User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        // L'amorçage ne dure que le temps de créer ce premier compte : ensuite, plus de création.
        $this->assertThrows(fn () => $this->userIn($tenant), SubscriptionRestricted::class);
    }

    public function test_the_migration_gave_every_pre_existing_tenant_a_subscription(): void
    {
        $this->assertInstanceOf(\App\Models\TenantSubscription::class, app(SubscriptionManager::class)->current(Tenant::query()->findOrFail(1)));
    }
}
