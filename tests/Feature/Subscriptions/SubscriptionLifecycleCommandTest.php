<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Enums\SubscriptionStatus;
use App\Models\SubscriptionNotificationLog;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Notifications\Subscriptions\PaymentConfirmed;
use App\Notifications\Subscriptions\PaymentFailed;
use App\Notifications\Subscriptions\SubscriptionExpired;
use App\Notifications\Subscriptions\SubscriptionExpiring;
use App\Notifications\Subscriptions\SubscriptionPlanChanged;
use App\Notifications\Subscriptions\SubscriptionReactivated;
use App\Notifications\Subscriptions\SubscriptionSuspended;
use App\Notifications\Subscriptions\TrialExpiring;
use App\Notifications\Subscriptions\TrialStarted;
use App\Services\Subscriptions\Payments\ManualSubscriptionPaymentGateway;
use App\Services\Subscriptions\SubscriptionBilling;
use App\Services\Subscriptions\SubscriptionManager;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

class SubscriptionLifecycleCommandTest extends SubscriptionTestCase
{
    private SubscriptionManager $manager;

    private User $master;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToDate('2026-10-10 02:00:00');
        $this->manager = app(SubscriptionManager::class);
        $this->tenant = $this->tenant();
        $this->master = $this->userIn($this->tenant);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function process(): void
    {
        $this->artisan('subscriptions:process')->assertSuccessful();
    }

    private function sentCount(string $class): int
    {
        return Notification::sent($this->master, $class)->count();
    }

    public function test_trial_start_and_plan_changes_notify_the_master_once(): void
    {
        $this->manager->startTrial($this->tenant, $this->plan('pro'));
        $this->manager->changePlan($this->tenant, $this->plan('essentiel'));
        $this->manager->suspend($this->tenant);
        $this->manager->reactivate($this->tenant);

        $this->assertSame(1, $this->sentCount(TrialStarted::class));
        $this->assertSame(1, $this->sentCount(SubscriptionPlanChanged::class));
        $this->assertSame(1, $this->sentCount(SubscriptionSuspended::class));
        $this->assertSame(1, $this->sentCount(SubscriptionReactivated::class));
    }

    public function test_warnings_go_out_at_d7_d3_d1_and_only_once_each(): void
    {
        $this->manager->activate($this->tenant, $this->plan('pro'), SubscriptionCycle::Monthly); // fin 2026-11-10 02:00

        $this->travelToDate('2026-10-31 03:00:00'); // J-10 : rien
        $this->process();
        $this->assertSame(0, $this->sentCount(SubscriptionExpiring::class));

        $this->travelToDate('2026-11-03 02:00:00'); // J-7
        $this->process();
        $this->process(); // second passage le même jour : aucun doublon
        $this->assertSame(1, $this->sentCount(SubscriptionExpiring::class));

        $this->travelToDate('2026-11-07 02:00:00'); // J-3
        $this->process();
        $this->assertSame(2, $this->sentCount(SubscriptionExpiring::class));

        $this->travelToDate('2026-11-09 02:00:00'); // J-1
        $this->process();
        $this->process();
        $this->assertSame(3, $this->sentCount(SubscriptionExpiring::class));

        $this->assertSame(3, SubscriptionNotificationLog::withoutGlobalScopes()->where('type', SubscriptionExpiring::type())->count());
    }

    public function test_trials_get_the_trial_wording(): void
    {
        $this->manager->startTrial($this->tenant, $this->plan('pro'), days: 3);
        $this->travelToDate('2026-10-11 03:00:00'); // J-2 -> palier J-3

        $this->process();

        $this->assertSame(1, $this->sentCount(TrialExpiring::class));
        $this->assertSame(0, $this->sentCount(SubscriptionExpiring::class));
    }

    public function test_expiry_updates_the_status_notifies_once_and_is_idempotent(): void
    {
        $sub = $this->manager->startTrial($this->tenant, $this->plan('pro'), days: 2);
        $this->travelToDate('2026-10-13 02:00:00');

        $this->process();
        $this->process();

        $this->assertSame(SubscriptionStatus::Expired, $sub->fresh()->status);
        $this->assertSame(1, $this->sentCount(SubscriptionExpired::class));
        $this->assertSame(1, TenantSubscription::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count(), 'aucune ligne créée ni supprimée');
    }

    public function test_expiry_deletes_nothing(): void
    {
        $agent = TenantContext::runAs($this->tenant->id, fn () => \App\Models\Agent::factory()->create());
        $this->manager->startTrial($this->tenant, $this->plan('pro'), days: 1);
        $this->travelToDate('2026-10-12 02:00:00');

        $this->process();

        $this->assertNotNull(\App\Models\Agent::withoutGlobalScopes()->find($agent->id));
        $this->assertNotNull(User::withoutGlobalScopes()->find($this->master->id));
        $this->assertSame(1, TenantSubscription::withoutGlobalScopes()->count() - TenantSubscription::withoutGlobalScopes()->where('tenant_id', 1)->count());
    }

    public function test_suspended_tenants_and_cancelled_subscriptions_receive_no_warning(): void
    {
        $this->manager->activate($this->tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $this->manager->cancel($this->tenant);
        $this->travelToDate('2026-11-09 02:00:00');
        $this->process();
        $this->assertSame(0, $this->sentCount(SubscriptionExpiring::class));
    }

    public function test_an_error_on_one_subscription_is_logged_and_does_not_stop_the_others(): void
    {
        $other = $this->tenant('Autre');
        $this->userIn($other);
        $this->manager->startTrial($this->tenant, $this->plan('pro'), days: 1);
        $broken = $this->manager->startTrial($other, $this->plan('pro'), days: 1);
        $this->travelToDate('2026-10-12 02:00:00');

        // Provoque une erreur sur un abonnement : valeur de cycle corrompue au niveau SQL (cas anormal, indépendant du moteur).
        \Illuminate\Support\Facades\DB::table('tenant_subscriptions')->where('id', $broken->id)->update(['cycle' => 'invalide']);
        Log::spy();

        $this->artisan('subscriptions:process')->assertFailed();

        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'subscriptions:process'))->once();
        $this->assertSame(1, TenantSubscription::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('status', 'expired')->count(), 'les autres tenants sont traités');
    }

    // ---------------------------------------------------------------- paiements

    public function test_a_manual_payment_requires_a_super_admin_and_a_reference_and_never_confirms_otherwise(): void
    {
        $this->manager->activate($this->tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $billing = app(SubscriptionBilling::class);
        $payment = $billing->request($this->tenant);

        $this->assertSame(SubscriptionPayment::PENDING, $payment->status);
        $this->assertSame(30000, $payment->amount);

        // Un master (ou personne) ne peut pas attester un encaissement.
        $this->assertThrows(fn () => $billing->confirm($payment, ['reference' => 'X', 'confirmed_by' => $this->master]), InvalidArgumentException::class);
        $this->assertThrows(fn () => $billing->confirm($payment, ['reference' => 'X']), InvalidArgumentException::class);
        // Sans référence non plus.
        $this->assertThrows(fn () => $billing->confirm($payment, ['reference' => ' ', 'confirmed_by' => $this->superAdmin()]), InvalidArgumentException::class);

        $this->assertSame(SubscriptionPayment::PENDING, $payment->fresh()->status);
        $this->assertSame('2026-11-10', $this->manager->current($this->tenant)->current_period_ends_at->toDateString(), 'abonnement inchangé');
    }

    public function test_a_confirmed_payment_renews_once_and_notifies(): void
    {
        $this->manager->activate($this->tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $billing = app(SubscriptionBilling::class);
        $payment = $billing->request($this->tenant);
        $admin = $this->superAdmin();

        $billing->confirm($payment, ['reference' => 'WAVE-123', 'confirmed_by' => $admin]);
        $billing->confirm($payment->fresh(), ['reference' => 'WAVE-123', 'confirmed_by' => $admin]); // rejoué

        $payment = $payment->fresh();
        $this->assertSame(SubscriptionPayment::CONFIRMED, $payment->status);
        $this->assertSame('WAVE-123', $payment->provider_reference);
        $this->assertSame($admin->id, $payment->confirmed_by);
        $this->assertSame('2026-12-10', $this->manager->current($this->tenant)->current_period_ends_at->toDateString(), 'une seule prolongation');
        $this->assertSame(1, $this->sentCount(PaymentConfirmed::class));
    }

    public function test_a_failed_payment_leaves_the_subscription_untouched(): void
    {
        $this->manager->activate($this->tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $billing = app(SubscriptionBilling::class);
        $payment = $billing->request($this->tenant);

        $billing->fail($payment, 'Fonds insuffisants');

        $this->assertSame(SubscriptionPayment::FAILED, $payment->fresh()->status);
        $this->assertSame('2026-11-10', $this->manager->current($this->tenant)->current_period_ends_at->toDateString());
        $this->assertSame(1, $this->sentCount(PaymentFailed::class));
        $this->assertThrows(fn () => $billing->confirm($payment->fresh(), ['reference' => 'R', 'confirmed_by' => $this->superAdmin()]), \App\Exceptions\InvalidSubscriptionTransition::class);
    }

    public function test_the_billing_depends_on_the_contract_not_on_a_provider(): void
    {
        $this->assertInstanceOf(ManualSubscriptionPaymentGateway::class, app(SubscriptionBilling::class)->gateway());
        $this->assertThrows(fn () => app(SubscriptionBilling::class)->gateway('cinetpay'), InvalidArgumentException::class);
    }

    public function test_a_confirmed_trial_payment_starts_the_paid_period_and_keeps_the_trial_in_history(): void
    {
        $this->manager->startTrial($this->tenant, $this->plan('pro'));
        $billing = app(SubscriptionBilling::class);
        $payment = $billing->request($this->tenant);

        $billing->confirm($payment, ['reference' => 'CASH-1', 'confirmed_by' => $this->superAdmin()]);

        $current = $this->manager->current($this->tenant);
        $this->assertSame(SubscriptionStatus::Active, $current->status);
        $this->assertCount(2, $this->manager->history($this->tenant));
        $this->assertSame(SubscriptionStatus::Replaced, $this->manager->history($this->tenant)->last()->status);
    }
}
