<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Models\SubscriptionChangeRequest;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Subscriptions\PaymentFailed;
use App\Services\Subscriptions\Payments\JekoPaymentReconciler;
use App\Services\Subscriptions\SubscriptionBilling;
use App\Services\Subscriptions\SubscriptionManager;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/** Un paiement Jèko resté « en attente » plus de 48 h est annulé : il ne bloque plus l'abonnement. */
class JekoPendingPaymentExpiryTest extends SubscriptionTestCase
{
    private const WEBHOOK_SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'app.url' => 'https://uvmaster.test',
            'services.jeko.base_url' => 'https://api.jeko.africa',
            'services.jeko.api_key' => 'test-api-key',
            'services.jeko.api_key_id' => 'test-api-key-id',
            'services.jeko.store_id' => 'store-test-id',
            'services.jeko.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
    }

    public function test_a_checkout_still_pending_after_48_hours_is_expired_and_frees_the_plan_change(): void
    {
        Notification::fake();
        $this->fakeJeko('pending');
        [$tenant, $master, $request, $payment] = $this->tenantWithPendingChange();

        $this->travelToDate(now()->addHours(49)->toDateTimeString());

        $this->assertSame('expired', app(JekoPaymentReconciler::class)->reconcile($payment));

        $this->assertSame(SubscriptionPayment::FAILED, $payment->fresh()->status);
        $this->assertStringContainsString('48', (string) $payment->fresh()->failure_reason);
        $this->assertSame(SubscriptionChangeRequest::CANCELLED, $request->fresh()->status);
        $this->assertCount(1, Notification::sent($master, PaymentFailed::class), 'le master est prévenu');

        // Plus rien ne bloque : une nouvelle demande de changement est possible.
        $new = app(SubscriptionBilling::class)->requestChange($tenant, $this->plan('entreprise'), SubscriptionCycle::Monthly, $master, 'jeko', 'wave');
        $this->assertSame(SubscriptionChangeRequest::PENDING, $new->status);
    }

    public function test_a_checkout_is_left_alone_before_48_hours(): void
    {
        $this->fakeJeko('pending');
        [, , $request, $payment] = $this->tenantWithPendingChange();

        $this->travelToDate(now()->addHours(47)->toDateTimeString());

        $this->assertSame('pending', app(JekoPaymentReconciler::class)->reconcile($payment));
        $this->assertSame(SubscriptionPayment::PENDING, $payment->fresh()->status);
        $this->assertSame(SubscriptionChangeRequest::PENDING, $request->fresh()->status);
    }

    public function test_a_payment_that_succeeded_at_jeko_is_confirmed_even_after_48_hours_never_expired(): void
    {
        $this->fakeJeko('success');
        [$tenant, , $request, $payment] = $this->tenantWithPendingChange();

        $this->travelToDate(now()->addHours(60)->toDateTimeString());

        $this->assertSame('confirmed', app(JekoPaymentReconciler::class)->reconcile($payment));
        $this->assertSame(SubscriptionPayment::CONFIRMED, $payment->fresh()->status);
        $this->assertSame(SubscriptionChangeRequest::APPLIED, $request->fresh()->status);
        $this->assertSame('entreprise', app(SubscriptionManager::class)->current($tenant)->plan->slug);
    }

    public function test_expiry_never_overwrites_a_payment_confirmed_in_the_meantime(): void
    {
        $this->fakeJeko('pending');
        [, , , $stale] = $this->tenantWithPendingChange();
        $this->travelToDate(now()->addHours(49)->toDateTimeString());

        // Le webhook confirme le paiement pendant que le rattrapage travaille encore sur une copie périmée.
        TenantContext::withoutTenancy(fn () => SubscriptionPayment::query()->whereKey($stale->id)->update(['status' => SubscriptionPayment::CONFIRMED]));

        $this->assertFalse(app(SubscriptionBilling::class)->expire($stale));
        $this->assertSame(SubscriptionPayment::CONFIRMED, $stale->fresh()->status);
    }

    public function test_a_late_signed_success_on_an_expired_payment_is_refused_and_raised_as_critical(): void
    {
        $this->fakeJeko('pending');
        [, , , $payment] = $this->tenantWithPendingChange();
        $this->travelToDate(now()->addHours(49)->toDateTimeString());
        app(JekoPaymentReconciler::class)->reconcile($payment);

        Log::spy();
        $body = json_encode([
            'id' => 'txn-late',
            'status' => 'success',
            'transactionType' => 'payment',
            'storeId' => 'store-test-id',
            'paymentMethod' => 'wave',
            'transactionDetails' => ['id' => $payment->provider_reference, 'reference' => $payment->metadata['merchant_reference']],
            'amount' => ['amount' => $payment->amount, 'currency' => 'XOF'],
        ]);

        $this->call('POST', '/api/webhooks/jeko', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_JEKO_EVENT' => 'TRANSACTION_COMPLETED',
            'HTTP_JEKO_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body)->assertStatus(409);

        $this->assertSame(SubscriptionPayment::FAILED, $payment->fresh()->status, 'rien n\'est appliqué');
        Log::shouldHaveReceived('critical')->once();
    }

    public function test_the_lifetime_is_configurable(): void
    {
        config()->set('subscriptions.pending_payment_ttl_hours', 2);
        $this->fakeJeko('pending');
        [, , , $payment] = $this->tenantWithPendingChange();

        $this->travelToDate(now()->addHours(3)->toDateTimeString());

        $this->assertSame('expired', app(JekoPaymentReconciler::class)->reconcile($payment));
    }

    public function test_the_mobile_page_tells_the_user_until_when_a_pending_payment_is_valid(): void
    {
        $this->fakeJeko('pending');
        [, $master, , $payment] = $this->tenantWithPendingChange();
        $this->actingAs($master);

        $deadline = app(SubscriptionBilling::class)->expiresAt($payment)->format('d/m à H:i');

        $this->get('/m/abonnement')->assertOk()->assertSee('valable jusqu\'au '.$deadline);
    }

    private function fakeJeko(string $status): void
    {
        $sequence = 0;

        Http::fake(function ($request) use ($status, &$sequence) {
            if ($request->method() === 'POST') {
                $id = 'payreq-expiry-'.++$sequence;

                return Http::response([
                    'id' => $id,
                    'status' => 'pending',
                    'redirectUrl' => 'https://pay.jeko.africa/pay_request/pr/'.$id,
                ], 201);
            }

            $id = basename((string) parse_url($request->url(), PHP_URL_PATH));
            $payment = TenantContext::withoutTenancy(fn () => SubscriptionPayment::query()->where('provider_reference', $id)->first());
            $body = [
                'id' => $id,
                'storeId' => 'store-test-id',
                'reference' => $payment?->metadata['merchant_reference'] ?? null,
                'status' => $status,
                'paymentMethod' => 'wave',
            ];

            if ($status === 'success') {
                $body['transaction'] = ['id' => 'txn-'.$id, 'status' => 'success', 'amount' => ['amount' => $payment->amount, 'currency' => 'XOF']];
            }

            return Http::response($body);
        });
    }

    /** @return array{0: Tenant, 1: User, 2: SubscriptionChangeRequest, 3: SubscriptionPayment} */
    private function tenantWithPendingChange(): array
    {
        $tenant = $this->tenant();
        $master = $this->userIn($tenant);
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);

        $request = app(SubscriptionBilling::class)->requestChange($tenant, $this->plan('entreprise'), SubscriptionCycle::Monthly, $master, 'jeko', 'wave');
        $payment = TenantContext::withoutTenancy(fn () => SubscriptionPayment::query()->where('tenant_id', $tenant->id)->latest('id')->firstOrFail());

        return [$tenant, $master, $request, $payment];
    }
}
