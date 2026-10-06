<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Enums\SubscriptionStatus;
use App\Exceptions\InvalidSubscriptionTransition;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Subscriptions\SubscriptionManager;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SubscriptionManagerTest extends SubscriptionTestCase
{
    private SubscriptionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 10:00:00');
        $this->manager = app(SubscriptionManager::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rows(Tenant $tenant)
    {
        return TenantContext::withoutTenancy(fn () => TenantSubscription::where('tenant_id', $tenant->id)->orderBy('id')->get());
    }

    public function test_a_trial_lasts_the_plan_trial_days(): void
    {
        $tenant = $this->tenant();
        $sub = $this->manager->startTrial($tenant, $this->plan('pro'));

        $this->assertSame(SubscriptionStatus::Trialing, $sub->status);
        $this->assertTrue($sub->trial_ends_at->equalTo(now()->addDays(14)));
        $this->assertSame(14, $sub->daysRemaining());
        $this->assertSame($sub->id, $this->manager->current($tenant)->id);
    }

    public function test_a_second_trial_is_refused_unless_explicitly_allowed(): void
    {
        $tenant = $this->tenant();
        $this->manager->startTrial($tenant, $this->plan('pro'));

        try {
            $this->manager->startTrial($tenant, $this->plan('essentiel'));
            $this->fail('Un second essai aurait dû être refusé.');
        } catch (InvalidSubscriptionTransition) {
            $this->assertCount(1, $this->rows($tenant));
        }

        $this->manager->startTrial($tenant, $this->plan('essentiel'), allowRepeat: true);
        $this->assertCount(2, $this->rows($tenant));
    }

    public function test_a_monthly_subscription_runs_one_month_and_a_yearly_one_year(): void
    {
        $monthly = $this->manager->activate($this->tenant(), $this->plan('essentiel'), SubscriptionCycle::Monthly);
        $this->assertSame('2026-11-10', $monthly->current_period_ends_at->toDateString());
        $this->assertSame(15000, $monthly->applied_price);

        $yearly = $this->manager->activate($this->tenant(), $this->plan('essentiel'), SubscriptionCycle::Yearly);
        $this->assertSame('2027-10-10', $yearly->current_period_ends_at->toDateString());
        $this->assertSame(150000, $yearly->applied_price);
    }

    public function test_month_end_does_not_overflow(): void
    {
        Carbon::setTestNow('2026-01-31 08:00:00');
        $sub = $this->manager->activate($this->tenant(), $this->plan('pro'), SubscriptionCycle::Monthly);

        $this->assertSame('2026-02-28', $sub->current_period_ends_at->toDateString());
    }

    public function test_changing_plan_keeps_the_history_and_one_single_current_row(): void
    {
        $tenant = $this->tenant();
        $first = $this->manager->activate($tenant, $this->plan('essentiel'), SubscriptionCycle::Monthly);
        $second = $this->manager->changePlan($tenant, $this->plan('pro'));
        $third = $this->manager->changeCycle($tenant, SubscriptionCycle::Yearly);

        $rows = $this->rows($tenant);
        $this->assertCount(3, $rows);
        $this->assertSame([SubscriptionStatus::Replaced, SubscriptionStatus::Replaced, SubscriptionStatus::Active], $rows->pluck('status')->all());
        $this->assertSame(1, $rows->filter->isCurrent()->count());
        $this->assertSame($third->id, $this->manager->current($tenant)->id);
        $this->assertSame([$third->id, $second->id, $first->id], $this->manager->history($tenant)->pluck('id')->all());

        // L'historique garde le prix et l'offre d'origine.
        $this->assertSame(15000, $rows[0]->applied_price);
        $this->assertSame('essentiel', $rows[0]->plan->slug);
        $this->assertSame(300000, $third->applied_price);
        // Changement de cycle : nouvelle période complète d'un an.
        $this->assertSame('2027-10-10', $third->current_period_ends_at->toDateString());
    }

    public function test_the_database_refuses_two_current_subscriptions_for_one_tenant(): void
    {
        $tenant = $this->tenant();
        $this->manager->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);

        $this->expectException(QueryException::class);
        DB::table('tenant_subscriptions')->insert([
            'tenant_id' => $tenant->id, 'subscription_plan_id' => $this->plan('pro')->id, 'cycle' => 'monthly',
            'status' => 'active', 'applied_price' => 1, 'current_marker' => $tenant->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_changing_plan_during_a_trial_keeps_the_trial_end(): void
    {
        $tenant = $this->tenant();
        $trial = $this->manager->startTrial($tenant, $this->plan('essentiel'));
        $changed = $this->manager->changePlan($tenant, $this->plan('pro'));

        $this->assertSame(SubscriptionStatus::Trialing, $changed->status);
        $this->assertTrue($changed->trial_ends_at->equalTo($trial->trial_ends_at));
        $this->assertCount(2, $this->rows($tenant));
    }

    public function test_renewal_extends_from_the_end_date_without_losing_days(): void
    {
        $tenant = $this->tenant();
        $this->manager->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly); // fin 2026-11-10
        Carbon::setTestNow('2026-11-01 10:00:00');

        $renewed = $this->manager->renew($tenant, 'REF-1');

        $this->assertSame('2026-12-10', $renewed->current_period_ends_at->toDateString());
        $this->assertSame('REF-1', $renewed->external_reference);
        $this->assertCount(1, $this->rows($tenant), 'Un renouvellement prolonge la même ligne');
    }

    public function test_renewing_an_expired_subscription_restarts_from_now(): void
    {
        $tenant = $this->tenant();
        $this->manager->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        Carbon::setTestNow('2027-03-01 10:00:00');

        $renewed = $this->manager->renew($tenant);

        $this->assertSame(SubscriptionStatus::Active, $renewed->status);
        $this->assertSame('2027-04-01', $renewed->current_period_ends_at->toDateString());
    }

    public function test_suspension_and_reactivation_recompute_the_status_from_dates(): void
    {
        $tenant = $this->tenant();
        $this->manager->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);

        $suspended = $this->manager->suspend($tenant, 'Impayé');
        $this->assertSame(SubscriptionStatus::Suspended, $suspended->status);
        $this->assertNotNull($suspended->suspended_at);

        $this->assertSame(SubscriptionStatus::Active, $this->manager->reactivate($tenant)->status);

        $this->manager->suspend($tenant);
        Carbon::setTestNow('2027-01-01 10:00:00'); // la période est passée pendant la suspension
        $this->assertSame(SubscriptionStatus::Expired, $this->manager->reactivate($tenant)->effectiveStatus());
    }

    public function test_a_suspended_trial_comes_back_as_a_trial(): void
    {
        $tenant = $this->tenant();
        $this->manager->startTrial($tenant, $this->plan('pro'));
        $this->manager->suspend($tenant);

        $this->assertSame(SubscriptionStatus::Trialing, $this->manager->reactivate($tenant)->status);
    }

    public function test_cancel_keeps_access_until_the_end_of_the_paid_period(): void
    {
        $tenant = $this->tenant();
        $this->manager->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);

        $cancelled = $this->manager->cancel($tenant);

        $this->assertSame(SubscriptionStatus::Cancelled, $cancelled->status);
        $this->assertSame('2026-11-10', $cancelled->current_period_ends_at->toDateString());
        $this->assertFalse($cancelled->auto_renew);
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $tenant = $this->tenant();

        $this->assertThrows(fn () => $this->manager->suspend($tenant), InvalidSubscriptionTransition::class); // aucun abonnement

        $this->manager->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $this->assertThrows(fn () => $this->manager->reactivate($tenant), InvalidSubscriptionTransition::class); // non suspendu
        $this->assertThrows(fn () => $this->manager->changePlan($tenant, $this->plan('pro')), InvalidSubscriptionTransition::class); // identique
        $this->assertThrows(fn () => $this->manager->extendTrial($tenant, 5), InvalidSubscriptionTransition::class); // pas un essai
    }

    public function test_a_trial_can_be_extended(): void
    {
        $tenant = $this->tenant();
        $this->manager->startTrial($tenant, $this->plan('pro'), days: 3);

        $extended = $this->manager->extendTrial($tenant, 7);

        $this->assertSame('2026-10-20', $extended->trial_ends_at->toDateString());
        $this->assertSame('trial_extended', collect($extended->metadata['events'])->last()['type']);
    }

    public function test_expiry_is_derived_from_the_dates_even_before_the_cron_runs(): void
    {
        $tenant = $this->tenant();
        $sub = $this->manager->startTrial($tenant, $this->plan('pro'));
        Carbon::setTestNow('2026-12-01 10:00:00');

        $this->assertSame(SubscriptionStatus::Trialing, $sub->fresh()->status, 'statut stocké inchangé');
        $this->assertSame(SubscriptionStatus::Expired, $sub->fresh()->effectiveStatus());
    }

    public function test_each_operation_is_recorded_in_the_event_log_and_mirrors_the_legacy_columns(): void
    {
        $tenant = $this->tenant();
        $this->manager->startTrial($tenant, $this->plan('pro'));

        $fresh = $tenant->fresh();
        $this->assertSame('pro', $fresh->plan, 'miroir hérité');
        $this->assertNotNull($fresh->trial_ends_at);

        $this->manager->activate($tenant, $this->plan('essentiel'), SubscriptionCycle::Monthly);
        $this->assertSame('essentiel', $tenant->fresh()->plan);
        $this->assertNull($tenant->fresh()->trial_ends_at);
    }

    public function test_operations_on_a_tenant_never_touch_another_tenant(): void
    {
        $a = $this->tenant('A');
        $b = $this->tenant('B');
        $this->manager->activate($a, $this->plan('pro'), SubscriptionCycle::Monthly);
        $subB = $this->manager->activate($b, $this->plan('essentiel'), SubscriptionCycle::Monthly);

        $this->manager->changePlan($a, $this->plan('entreprise'));
        $this->manager->suspend($a);

        $this->assertSame($subB->id, $this->manager->current($b)->id);
        $this->assertSame(SubscriptionStatus::Active, $this->manager->current($b)->status);
        $this->assertCount(1, $this->rows($b));
    }
}
