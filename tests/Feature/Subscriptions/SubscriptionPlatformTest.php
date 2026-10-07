<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Enums\SubscriptionStatus;
use App\Filament\Platform\Resources\SubscriptionPlanResource\Pages\CreateSubscriptionPlan;
use App\Filament\Platform\Resources\SubscriptionPlanResource\Pages\EditSubscriptionPlan;
use App\Filament\Platform\Resources\SubscriptionPlanResource\Pages\ListSubscriptionPlans;
use App\Filament\Platform\Resources\TenantSubscriptionResource\Pages\ListTenantSubscriptions;
use App\Filament\Platform\Widgets\SubscriptionStatsOverview;
use App\Filament\Platform\Widgets\TenantHealthOverview;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\Subscriptions\SubscriptionManager;
use Filament\Facades\Filament;
use Livewire\Livewire;

class SubscriptionPlatformTest extends SubscriptionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('platform'));
    }

    private function manager(): SubscriptionManager
    {
        return app(SubscriptionManager::class);
    }

    // ---------------------------------------------------------------- accès (côté serveur)

    public function test_only_a_real_super_admin_reaches_the_platform_subscription_pages(): void
    {
        $tenant = $this->tenant();
        $master = $this->userIn($tenant, 'master');
        $admin = $this->userIn($tenant, 'admin');

        // Compte de tenant portant par erreur le rôle super_admin : isSuperAdmin() le refuse (tenant_id non nul).
        $fake = $this->userIn($tenant, 'master');
        $fake->assignRole('super_admin');
        $this->assertFalse($fake->fresh()->isSuperAdmin());
        $super = $this->superAdmin(); // créé hors session : un utilisateur connecté imposerait son tenant

        foreach ([$master, $admin, $fake] as $intruder) {
            $this->actingAs($intruder);
            Livewire::test(ListTenantSubscriptions::class)->assertForbidden();
            Livewire::test(ListSubscriptionPlans::class)->assertForbidden();
            Livewire::test(CreateSubscriptionPlan::class)->assertForbidden();
            $this->assertFalse($intruder->canAccessPanel(Filament::getPanel('platform')));
        }

        $this->actingAs($super);
        Livewire::test(ListTenantSubscriptions::class)->assertSuccessful();
        Livewire::test(ListSubscriptionPlans::class)->assertSuccessful();
    }

    public function test_platform_http_routes_are_closed_to_tenant_users(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->userIn($tenant, 'master'));

        foreach (['/platform/tenant-subscriptions', '/platform/subscription-plans', '/platform/subscription-plans/create'] as $url) {
            $status = $this->get($url)->getStatusCode();
            $this->assertContains($status, [302, 403], "$url doit être fermé à un master (reçu $status)");
        }
    }

    public function test_the_policies_follow_is_super_admin_and_tenant_ownership(): void
    {
        $a = $this->tenant('A');
        $b = $this->tenant('B');
        $subA = $this->manager()->activate($a, $this->plan('pro'), SubscriptionCycle::Monthly);
        $subB = $this->manager()->activate($b, $this->plan('pro'), SubscriptionCycle::Monthly);
        $masterA = $this->userIn($a, 'master');
        $comptable = $this->userIn($a, 'comptable');
        $super = $this->superAdmin();

        $this->assertTrue($super->can('view', $subB));
        $this->assertTrue($masterA->can('view', $subA));
        $this->assertFalse($masterA->can('view', $subB), 'jamais l\'abonnement d\'un autre tenant');
        $this->assertFalse($comptable->can('view', $subA));
        $this->assertFalse($masterA->can('update', $subA));
        $this->assertFalse($masterA->can('create', \App\Models\TenantSubscription::class));
        $this->assertFalse($super->can('delete', $subA), 'l\'historique ne se supprime jamais');
        $this->assertFalse($masterA->can('viewAny', SubscriptionPlan::class));
        $this->assertTrue($super->can('viewAny', SubscriptionPlan::class));
    }

    // ---------------------------------------------------------------- offres

    public function test_the_super_admin_creates_edits_and_deactivates_a_plan(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateSubscriptionPlan::class)
            ->fillForm([
                'name' => 'Découverte', 'slug' => 'decouverte', 'monthly_price' => 5000, 'yearly_price' => 50000, 'trial_days' => 7,
                'max_users' => 2, 'max_agents' => null, 'features' => ['exports_pdf'], 'display_order' => 0,
                'visual_variant' => 'default', 'is_active' => true,
            ])
            ->call('create')->assertHasNoFormErrors();

        $plan = SubscriptionPlan::where('slug', 'decouverte')->firstOrFail();
        $this->assertNull($plan->max_agents, 'vide = illimité');
        $this->assertSame(5000, $plan->monthly_price);

        Livewire::test(EditSubscriptionPlan::class, ['record' => $plan->getRouteKey()])
            ->fillForm(['monthly_price' => 6000, 'is_featured' => true])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(6000, $plan->fresh()->monthly_price);

        Livewire::test(ListSubscriptionPlans::class)->callTableAction('toggle', $plan);
        $this->assertFalse($plan->fresh()->is_active);
    }

    public function test_zero_is_rejected_as_a_limit_so_it_can_never_mean_unlimited(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(CreateSubscriptionPlan::class)
            ->fillForm(['name' => 'Zéro', 'slug' => 'zero', 'monthly_price' => 1, 'yearly_price' => 1, 'trial_days' => 0, 'max_users' => 0, 'visual_variant' => 'default'])
            ->call('create')->assertHasFormErrors(['max_users']);
    }

    public function test_a_used_plan_has_no_delete_action_and_the_server_refuses_it_anyway(): void
    {
        $this->actingAs($this->superAdmin());
        $used = $this->plan('entreprise'); // utilisée par l'abonnement hérité du tenant n°1

        Livewire::test(ListSubscriptionPlans::class)->assertTableActionHidden('delete', $used);

        $this->assertFalse(auth()->user()->can('delete', $used));
        $this->assertThrows(fn () => $used->delete(), \LogicException::class);
    }

    // ---------------------------------------------------------------- abonnements

    public function test_the_super_admin_runs_the_whole_subscription_lifecycle_from_the_list(): void
    {
        $this->actingAs($this->superAdmin());
        $tenant = $this->tenant();
        $this->userIn($tenant);

        $list = Livewire::test(ListTenantSubscriptions::class);

        $list->callTableAction('assign', data: [
            'tenant_id' => $tenant->id, 'plan_id' => $this->plan('essentiel')->id, 'cycle' => 'monthly', 'mode' => 'trial',
        ])->assertHasNoTableActionErrors();
        $current = $this->manager()->current($tenant);
        $this->assertSame(SubscriptionStatus::Trialing, $current->status);

        $list->callTableAction('extend_trial', $current, data: ['days' => 5]);
        $this->assertSame(19, $this->manager()->current($tenant)->daysRemaining());

        $list->callTableAction('change_plan', $this->manager()->current($tenant), data: ['plan_id' => $this->plan('pro')->id, 'cycle' => 'yearly']);
        $this->assertSame('pro', $this->manager()->current($tenant)->plan->slug);
        $this->assertCount(2, $this->manager()->history($tenant), 'ancienne ligne conservée');

        $list->callTableAction('payment', $this->manager()->current($tenant), data: ['reference' => 'CASH-9']);
        $this->assertSame(SubscriptionStatus::Active, $this->manager()->current($tenant)->status);

        $list->callTableAction('suspend', $this->manager()->current($tenant), data: ['reason' => 'test']);
        $this->assertSame(SubscriptionStatus::Suspended, $this->manager()->current($tenant)->status);

        $list->callTableAction('reactivate', $this->manager()->current($tenant));
        $this->assertSame(SubscriptionStatus::Active, $this->manager()->current($tenant)->status);

        $list->callTableAction('cancel', $this->manager()->current($tenant), data: ['immediately' => false]);
        $this->assertSame(SubscriptionStatus::Cancelled, $this->manager()->current($tenant)->status);
    }

    public function test_a_second_trial_is_refused_from_the_ui_with_a_clear_message(): void
    {
        $this->actingAs($this->superAdmin());
        $tenant = $this->tenant();
        $this->manager()->startTrial($tenant, $this->plan('pro'));

        Livewire::test(ListTenantSubscriptions::class)->callTableAction('assign', data: [
            'tenant_id' => $tenant->id, 'plan_id' => $this->plan('pro')->id, 'cycle' => 'monthly', 'mode' => 'trial',
        ]);

        $this->assertCount(1, $this->manager()->history($tenant));
    }

    public function test_creating_a_tenant_from_the_platform_gives_it_a_trial_on_the_chosen_plan(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(\App\Filament\Platform\Resources\TenantResource\Pages\CreateTenant::class)
            ->fillForm([
                'name' => 'Société Offre', 'status' => 'active', 'subscription_plan_id' => $this->plan('essentiel')->id,
                'subscription_cycle' => 'yearly', 'subscription_mode' => 'trial',
                'admin_name' => 'Admin', 'admin_email' => 'admin-offre@example.com', 'admin_password' => 'un-mot-de-passe-long',
            ])->call('create')->assertHasNoFormErrors();

        $tenant = Tenant::where('name', 'Société Offre')->firstOrFail();
        $current = $this->manager()->current($tenant);
        $this->assertSame('essentiel', $current->plan->slug);
        $this->assertSame(SubscriptionStatus::Trialing, $current->status);
        $this->assertSame(SubscriptionCycle::Yearly, $current->cycle);
    }

    // ---------------------------------------------------------------- tableau de bord

    public function test_dashboard_figures_come_from_subscriptions_and_mrr_is_computed_per_cycle(): void
    {
        $monthly = $this->tenant();
        $yearly = $this->tenant();
        $trial = $this->tenant();
        $this->manager()->activate($monthly, $this->plan('pro'), SubscriptionCycle::Monthly);       // MRR 30 000, ARR 360 000
        $this->manager()->activate($yearly, $this->plan('pro'), SubscriptionCycle::Yearly);         // MRR 25 000, ARR 300 000
        $this->manager()->startTrial($trial, $this->plan('entreprise'));                             // ne compte pas dans le MRR

        // Mentir sur la colonne héritée ne change rien : les chiffres viennent des abonnements.
        Tenant::query()->whereKey($trial->id)->update(['plan' => 'entreprise-payante']);

        $m = app(\App\Services\Subscriptions\SubscriptionMetrics::class)->snapshot();

        $this->assertSame(55000, $m['mrr']);
        $this->assertSame(660000, $m['arr']);
        $this->assertSame(1, $m['trialing']);
        // « active » inclut l'abonnement hérité du tenant n°1 (prix 0 : aucun poids dans le MRR).
        $this->assertSame(3, $m['active']);
        $this->assertSame(2, $m['by_plan']['Pro']['count']);
        $this->assertSame(55000, $m['by_plan']['Pro']['mrr']);
    }

    public function test_expirations_within_seven_days_are_counted(): void
    {
        $soon = $this->tenant();
        $later = $this->tenant();
        $trial = $this->tenant();
        $this->manager()->activate($soon, $this->plan('pro'), SubscriptionCycle::Monthly, now()->subDays(25));   // fin dans ~5 j
        $this->manager()->activate($later, $this->plan('pro'), SubscriptionCycle::Monthly);                      // fin dans 1 mois
        $this->manager()->startTrial($trial, $this->plan('pro'), days: 3);

        $m = app(\App\Services\Subscriptions\SubscriptionMetrics::class)->snapshot();

        $this->assertSame(1, $m['expiring_soon']);
        $this->assertSame(1, $m['trials_expiring_soon']);
    }

    public function test_dashboard_widgets_are_hidden_from_non_super_admins(): void
    {
        $super = $this->superAdmin();
        $this->actingAs($this->userIn($this->tenant(), 'master'));
        $this->assertFalse(SubscriptionStatsOverview::canView());
        $this->assertFalse(TenantHealthOverview::canView());

        $this->actingAs($super);
        $this->assertTrue(SubscriptionStatsOverview::canView());
        $this->assertTrue(TenantHealthOverview::canView());
        Livewire::test(SubscriptionStatsOverview::class)->assertSuccessful();
        Livewire::test(TenantHealthOverview::class)->assertSuccessful();
    }
}
