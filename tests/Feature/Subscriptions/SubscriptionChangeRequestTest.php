<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Enums\SubscriptionStatus;
use App\Exceptions\InvalidSubscriptionTransition;
use App\Livewire\Mobile\Subscription\Show;
use App\Models\SubscriptionChangeRequest;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Subscriptions\SubscriptionChangeRequested;
use App\Notifications\Subscriptions\SubscriptionRenewalDue;
use App\Services\Subscriptions\SubscriptionBilling;
use App\Services\Subscriptions\SubscriptionManager;
use App\Services\Subscriptions\SubscriptionProration;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Risques « pas de libre-service », « pas de proratisation » et « auto_renew sans effet ». */
class SubscriptionChangeRequestTest extends SubscriptionTestCase
{
    private SubscriptionManager $manager;

    private SubscriptionBilling $billing;

    private Tenant $tenant;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelToDate('2026-10-10 10:00:00');
        $this->manager = app(SubscriptionManager::class);
        $this->billing = app(SubscriptionBilling::class);
        $this->tenant = $this->tenant();
        $this->master = $this->userIn($this->tenant);
        $this->manager->activate($this->tenant, $this->plan('pro'), SubscriptionCycle::Monthly); // 30 000, fin 2026-11-10 10:00
        $this->travelToDate('2026-10-25 10:00:00'); // 16 jours restants sur 31
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pendingPayments(Tenant $tenant)
    {
        return TenantContext::withoutTenancy(fn () => SubscriptionPayment::where('tenant_id', $tenant->id)->where('status', 'pending')->get());
    }

    // ---------------------------------------------------------------- prorata

    public function test_an_upgrade_in_the_middle_of_the_period_is_prorated_to_the_second(): void
    {
        $quote = app(SubscriptionProration::class)->quote($this->manager->current($this->tenant), $this->plan('entreprise'), SubscriptionCycle::Monthly);

        $credit = (int) round(30000 * 16 / 31);
        $charge = (int) round(60000 * 16 / 31);
        $this->assertSame($credit, $quote['credit']);
        $this->assertSame($charge, $quote['charge']);
        $this->assertSame($charge - $credit, $quote['amount_due']);
        $this->assertSame(0.5161, $quote['ratio']);
        $this->assertSame('2026-11-10', substr($quote['new_period_ends_at'], 0, 10), 'même échéance en cas de même cycle');
    }

    public function test_a_downgrade_costs_nothing_and_a_cycle_change_starts_a_fresh_period(): void
    {
        $proration = app(SubscriptionProration::class);
        $current = $this->manager->current($this->tenant);

        $this->assertSame(0, $proration->quote($current, $this->plan('essentiel'), SubscriptionCycle::Monthly)['amount_due']);

        $yearly = $proration->quote($current, $this->plan('pro'), SubscriptionCycle::Yearly);
        $this->assertSame(300000 - (int) round(30000 * 16 / 31), $yearly['amount_due']);
        $this->assertSame('2027-10-25', substr($yearly['new_period_ends_at'], 0, 10));
        $this->assertTrue($yearly['cycle_changed']);
    }

    public function test_nothing_is_due_during_a_trial_or_for_a_grandfathered_subscription(): void
    {
        $proration = app(SubscriptionProration::class);
        $trial = $this->tenant();
        $trialSub = $this->manager->startTrial($trial, $this->plan('pro'));
        $this->assertSame(0, $proration->quote($trialSub, $this->plan('entreprise'), SubscriptionCycle::Monthly)['amount_due']);

        $legacy = Tenant::query()->findOrFail(1); // abonnement hérité : prix 0, sans échéance
        $this->assertSame(0, $proration->quote($this->manager->current($legacy), $this->plan('essentiel'), SubscriptionCycle::Yearly)['amount_due']);
    }

    public function test_a_direct_platform_change_records_the_quote_in_the_history(): void
    {
        $changed = $this->manager->changePlan($this->tenant, $this->plan('entreprise'));

        $event = collect($changed->metadata['events'])->last();
        $this->assertSame('plan_changed', $event['type']);
        $this->assertSame((int) round(60000 * 16 / 31) - (int) round(30000 * 16 / 31), $event['quote']['amount_due']);
    }

    // ---------------------------------------------------------------- libre-service : demande -> paiement -> application

    public function test_an_upgrade_request_creates_a_pending_payment_and_changes_nothing_until_it_is_confirmed(): void
    {
        $request = $this->billing->requestChange($this->tenant, $this->plan('entreprise'), SubscriptionCycle::Monthly, $this->master);

        $this->assertSame(SubscriptionChangeRequest::PENDING, $request->status);
        $this->assertSame('pro', $this->manager->current($this->tenant)->plan->slug, 'rien ne change avant le paiement');
        $payments = $this->pendingPayments($this->tenant);
        $this->assertCount(1, $payments);
        $this->assertSame($request->quote['amount_due'], $payments->first()->amount);
        $this->assertSame('plan_change', $payments->first()->metadata['purpose']);
        Notification::assertSentTo($this->master, SubscriptionChangeRequested::class);
    }

    public function test_confirming_the_payment_applies_the_change_once_and_keeps_the_history(): void
    {
        $request = $this->billing->requestChange($this->tenant, $this->plan('entreprise'), SubscriptionCycle::Monthly, $this->master);
        $payment = $this->pendingPayments($this->tenant)->first();
        $admin = $this->superAdmin();

        $this->billing->confirm($payment, ['reference' => 'WAVE-77', 'confirmed_by' => $admin]);
        $this->billing->confirm($payment->fresh(), ['reference' => 'WAVE-77', 'confirmed_by' => $admin]); // rejoué

        $this->assertSame('entreprise', $this->manager->current($this->tenant)->plan->slug);
        $this->assertCount(2, $this->manager->history($this->tenant));
        $this->assertSame(SubscriptionChangeRequest::APPLIED, TenantContext::withoutTenancy(fn () => SubscriptionChangeRequest::find($request->id))->status);
        $this->assertSame('2026-11-10', $this->manager->current($this->tenant)->current_period_ends_at->toDateString(), 'la période n\'est pas prolongée par un paiement de changement');
    }

    public function test_a_downgrade_request_is_applied_immediately_without_payment(): void
    {
        $request = $this->billing->requestChange($this->tenant, $this->plan('essentiel'), SubscriptionCycle::Monthly, $this->master);

        $this->assertSame(SubscriptionChangeRequest::APPLIED, $request->status);
        $this->assertSame('essentiel', $this->manager->current($this->tenant)->plan->slug);
        $this->assertCount(0, $this->pendingPayments($this->tenant));
    }

    public function test_a_new_request_cancels_the_previous_pending_one(): void
    {
        $first = $this->billing->requestChange($this->tenant, $this->plan('entreprise'), SubscriptionCycle::Monthly, $this->master);
        $second = $this->billing->requestChange($this->tenant, $this->plan('pro'), SubscriptionCycle::Yearly, $this->master);

        $this->assertSame(SubscriptionChangeRequest::CANCELLED, TenantContext::withoutTenancy(fn () => SubscriptionChangeRequest::find($first->id))->status);
        $this->assertSame(SubscriptionChangeRequest::PENDING, $second->status);
    }

    public function test_the_payment_of_a_superseded_request_is_cancelled_and_can_no_longer_be_confirmed(): void
    {
        $this->billing->requestChange($this->tenant, $this->plan('entreprise'), SubscriptionCycle::Monthly, $this->master);
        $stale = $this->pendingPayments($this->tenant)->first();

        $this->billing->requestChange($this->tenant, $this->plan('pro'), SubscriptionCycle::Yearly, $this->master);

        $this->assertSame(SubscriptionPayment::CANCELLED, $stale->fresh()->status, 'l\'ancien paiement est annulé avec sa demande');
        $this->assertCount(1, $this->pendingPayments($this->tenant), 'un seul paiement en attente');
        $this->assertThrows(fn () => $this->billing->confirm($stale->fresh(), ['reference' => 'X', 'confirmed_by' => $this->superAdmin()]), InvalidSubscriptionTransition::class);
        $this->assertSame('pro', $this->manager->current($this->tenant)->plan->slug);
    }

    public function test_a_payment_whose_request_was_cancelled_behind_its_back_is_refused_not_cashed(): void
    {
        $request = $this->billing->requestChange($this->tenant, $this->plan('entreprise'), SubscriptionCycle::Monthly, $this->master);
        $payment = $this->pendingPayments($this->tenant)->first();
        TenantContext::runAs($this->tenant->id, fn () => $request->forceFill(['status' => SubscriptionChangeRequest::CANCELLED])->save());

        $this->assertThrows(fn () => $this->billing->confirm($payment, ['reference' => 'X', 'confirmed_by' => $this->superAdmin()]), InvalidSubscriptionTransition::class);
        $this->assertSame(SubscriptionPayment::PENDING, $payment->fresh()->status, 'aucun encaissement enregistré');
    }

    public function test_the_platform_can_reject_a_request_and_its_payment_is_cancelled(): void
    {
        $request = $this->billing->requestChange($this->tenant, $this->plan('entreprise'), SubscriptionCycle::Monthly, $this->master);

        $this->billing->rejectChange($request, $this->superAdmin(), 'Offre indisponible');

        $this->assertSame(SubscriptionChangeRequest::REJECTED, TenantContext::withoutTenancy(fn () => SubscriptionChangeRequest::find($request->id))->status);
        $this->assertCount(0, $this->pendingPayments($this->tenant));
        $this->assertSame('pro', $this->manager->current($this->tenant)->plan->slug);
    }

    public function test_only_a_super_admin_can_reject(): void
    {
        $request = $this->billing->requestChange($this->tenant, $this->plan('entreprise'), SubscriptionCycle::Monthly, $this->master);

        $this->assertThrows(fn () => $this->billing->rejectChange($request, $this->master), HttpException::class);
    }

    public function test_requests_for_unavailable_or_identical_offers_or_expired_subscriptions_are_refused(): void
    {
        $this->assertThrows(fn () => $this->billing->requestChange($this->tenant, $this->plan('pro'), SubscriptionCycle::Monthly, $this->master), InvalidSubscriptionTransition::class);

        $this->plan('entreprise')->update(['is_active' => false]);
        $this->assertThrows(fn () => $this->billing->requestChange($this->tenant, $this->plan('entreprise'), SubscriptionCycle::Monthly, $this->master), InvalidSubscriptionTransition::class);

        $this->travelToDate('2027-03-01 10:00:00'); // abonnement échu : on le renouvelle d'abord
        $this->assertThrows(fn () => $this->billing->requestChange($this->tenant, $this->plan('essentiel'), SubscriptionCycle::Monthly, $this->master), InvalidSubscriptionTransition::class);
    }

    public function test_requests_and_payments_are_isolated_between_tenants(): void
    {
        $other = $this->tenant('Autre');
        $otherMaster = $this->userIn($other);
        $this->manager->activate($other, $this->plan('pro'), SubscriptionCycle::Monthly);
        $this->billing->requestChange($other, $this->plan('entreprise'), SubscriptionCycle::Monthly, $otherMaster);
        $this->billing->requestChange($this->tenant, $this->plan('entreprise'), SubscriptionCycle::Monthly, $this->master);

        $this->actingAs($this->master);
        $this->assertSame([$this->tenant->id], SubscriptionChangeRequest::query()->pluck('tenant_id')->unique()->all());
        $this->assertSame([$this->tenant->id], SubscriptionPayment::query()->pluck('tenant_id')->unique()->all());
    }

    // ---------------------------------------------------------------- écran mobile

    public function test_a_master_requests_a_change_from_the_mobile_page_and_the_server_recomputes_everything(): void
    {
        $this->actingAs($this->master);

        Livewire::test(Show::class)->call('requestChange', $this->plan('entreprise')->id, 'monthly')->assertSee('Demande enregistrée');

        $request = TenantContext::withoutTenancy(fn () => SubscriptionChangeRequest::where('tenant_id', $this->tenant->id)->firstOrFail());
        $this->assertSame($this->plan('entreprise')->id, $request->requested_plan_id);
        $this->assertSame((int) round(60000 * 16 / 31) - (int) round(30000 * 16 / 31), $request->quote['amount_due']);
    }

    public function test_the_mobile_page_displays_the_amount_due_and_pending_payments(): void
    {
        $this->billing->requestChange($this->tenant, $this->plan('entreprise'), SubscriptionCycle::Monthly, $this->master);
        $this->actingAs($this->master);

        $this->get('/m/abonnement')->assertOk()->assertSee('à régler')->assertSee('Paiements en attente')->assertSee('Demande en cours');
    }

    public function test_other_roles_cannot_use_the_request_actions(): void
    {
        $this->actingAs($this->userIn($this->tenant, 'comptable'));

        Livewire::test(Show::class)->assertForbidden();
    }

    // ---------------------------------------------------------------- auto_renew

    public function test_auto_renew_issues_a_renewal_invoice_once_at_expiry(): void
    {
        $this->manager->setAutoRenew($this->tenant, true);
        $this->travelToDate('2026-11-11 02:00:00');

        $this->artisan('subscriptions:process')->assertSuccessful();
        $this->artisan('subscriptions:process')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Expired, $this->manager->current($this->tenant)->status);
        $payments = $this->pendingPayments($this->tenant);
        $this->assertCount(1, $payments, 'une seule facture, même si le traitement est relancé');
        $this->assertSame(30000, $payments->first()->amount);
        $this->assertSame('renewal', $payments->first()->metadata['purpose']);
        Notification::assertSentToTimes($this->master, SubscriptionRenewalDue::class, 1);
    }

    public function test_without_auto_renew_no_invoice_is_issued(): void
    {
        $this->travelToDate('2026-11-11 02:00:00');

        $this->artisan('subscriptions:process')->assertSuccessful();

        $this->assertCount(0, $this->pendingPayments($this->tenant));
        Notification::assertNotSentTo($this->master, SubscriptionRenewalDue::class);
    }

    public function test_paying_the_renewal_invoice_reactivates_the_subscription(): void
    {
        $this->manager->setAutoRenew($this->tenant, true);
        $this->travelToDate('2026-11-11 02:00:00');
        $this->artisan('subscriptions:process');
        $invoice = $this->pendingPayments($this->tenant)->first();

        $this->billing->confirm($invoice, ['reference' => 'CASH-5', 'confirmed_by' => $this->superAdmin()]);

        $current = $this->manager->current($this->tenant);
        $this->assertSame(SubscriptionStatus::Active, $current->status);
        $this->assertSame('2026-12-11', $current->current_period_ends_at->toDateString());
    }

    public function test_the_tenant_can_toggle_auto_renew_from_the_mobile_page(): void
    {
        $this->actingAs($this->master);

        Livewire::test(Show::class)->call('toggleAutoRenew');

        $this->assertTrue($this->manager->current($this->tenant)->auto_renew);
    }
}
