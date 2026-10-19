<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionCycle;
use App\Exceptions\InvalidSubscriptionTransition;
use App\Filament\Platform\Resources\TenantSubscriptionResource\Pages\ListTenantSubscriptions;
use App\Livewire\Mobile\Subscription\Show;
use App\Models\SubscriptionChangeRequest;
use App\Models\SubscriptionPayment;
use App\Notifications\Subscriptions\PaymentFailed;
use App\Services\Subscriptions\Payments\JekoPaymentReconciler;
use App\Services\Subscriptions\Payments\JekoSubscriptionPaymentGateway;
use App\Services\Subscriptions\SubscriptionBilling;
use App\Services\Subscriptions\SubscriptionManager;
use App\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;

class JekoPaymentGatewayTest extends SubscriptionTestCase
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
            'subscriptions.gateways.jeko' => JekoSubscriptionPaymentGateway::class,
        ]);
    }

    public function test_it_creates_a_jeko_checkout_with_the_selected_payment_method(): void
    {
        Http::fake([
            'https://api.jeko.africa/partner_api/payment_requests' => Http::response([
                'id' => 'payreq-123',
                'reference' => 'SUB-1-TEST',
                'status' => 'pending',
                'redirectUrl' => 'https://pay.jeko.africa/pay_request/pr/payreq-123',
            ], 201),
        ]);

        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);

        $payment = app(SubscriptionBilling::class)->request($tenant, 'jeko', 'orange');

        $this->assertSame(SubscriptionPayment::PENDING, $payment->status);
        $this->assertSame(30000, $payment->amount);
        $this->assertSame('jeko', $payment->gateway);
        $this->assertSame('payreq-123', $payment->provider_reference);
        $this->assertSame('https://pay.jeko.africa/pay_request/pr/payreq-123', $payment->metadata['redirect_url']);
        $this->assertSame('orange', $payment->metadata['payment_method']);
        $this->assertSame(JekoSubscriptionPaymentGateway::AMOUNT_UNIT_VERSION, $payment->metadata['amount_unit_version']);
        $this->assertNotEmpty($payment->metadata['merchant_reference']);

        Http::assertSent(function (Request $request) use ($payment): bool {
            return $request->url() === 'https://api.jeko.africa/partner_api/payment_requests'
                && $request->hasHeader('X-API-KEY', 'test-api-key')
                && $request->hasHeader('X-API-KEY-ID', 'test-api-key-id')
                && $request['storeId'] === 'store-test-id'
                && $request['amountCents'] === 3000000
                && $request['currency'] === 'XOF'
                && $request['reference'] === $payment->metadata['merchant_reference']
                && $request['paymentDetails']['type'] === 'redirect'
                && $request['paymentDetails']['data']['paymentMethod'] === 'orange'
                && str_starts_with($request['paymentDetails']['data']['successUrl'], 'https://uvmaster.test/paiements/jeko/succes')
                && str_starts_with($request['paymentDetails']['data']['errorUrl'], 'https://uvmaster.test/paiements/jeko/erreur');
        });
    }

    public function test_an_annual_checkout_uses_the_exact_yearly_price(): void
    {
        Http::fake([
            'https://api.jeko.africa/partner_api/payment_requests' => Http::response([
                'id' => 'payreq-yearly',
                'status' => 'pending',
                'redirectUrl' => 'https://pay.jeko.africa/pay_request/pr/payreq-yearly',
            ], 201),
        ]);

        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Yearly);

        $payment = app(SubscriptionBilling::class)->request($tenant, 'jeko', 'wave');

        $this->assertSame(300000, $payment->amount);
        Http::assertSent(fn (Request $request): bool => $request['amountCents'] === 30000000);
    }

    public function test_a_legacy_or_outdated_pending_checkout_is_replaced_with_the_current_offer_price(): void
    {
        Http::fake([
            'https://api.jeko.africa/partner_api/payment_requests' => Http::response([
                'id' => 'payreq-current-price',
                'status' => 'pending',
                'redirectUrl' => 'https://pay.jeko.africa/pay_request/pr/payreq-current-price',
            ], 201),
        ]);

        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $legacy = $this->pendingPayment($tenant, 'payreq-legacy');
        $this->plan('pro')->update(['monthly_price' => 45050]);

        $payment = app(SubscriptionBilling::class)->request($tenant, 'jeko', 'wave');

        $this->assertNotSame($legacy->id, $payment->id);
        $this->assertSame(SubscriptionPayment::CANCELLED, $legacy->fresh()->status);
        $this->assertSame(45050, $payment->amount);
        Http::assertSent(fn (Request $request): bool => $request['amountCents'] === 4505000);
    }

    public function test_an_invalid_payment_method_is_rejected_without_calling_jeko(): void
    {
        Http::fake();

        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);

        $this->assertThrows(
            fn () => app(SubscriptionBilling::class)->request($tenant, 'jeko', 'card'),
            \InvalidArgumentException::class,
        );

        Http::assertNothingSent();
    }

    public function test_a_signed_success_webhook_renews_once_and_rejects_tampering(): void
    {
        Http::fake([
            'https://api.jeko.africa/partner_api/payment_requests' => Http::response([
                'id' => 'payreq-456',
                'reference' => 'SUB-2-TEST',
                'status' => 'pending',
                'redirectUrl' => 'https://pay.jeko.africa/pay_request/pr/payreq-456',
            ], 201),
        ]);

        $tenant = $this->tenant();
        $manager = app(SubscriptionManager::class);
        $manager->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $payment = app(SubscriptionBilling::class)->request($tenant, 'jeko', 'wave');
        $initialEnd = $manager->current($tenant)->current_period_ends_at->copy();

        $payload = [
            'id' => 'txn-456',
            'amount' => ['amount' => $payment->amount * 100, 'currency' => 'XOF'],
            'status' => 'success',
            'paymentMethod' => 'wave',
            'transactionType' => 'payment',
            'storeId' => 'store-test-id',
            'transactionDetails' => [
                'id' => 'payreq-456',
                'reference' => $payment->metadata['merchant_reference'],
            ],
        ];

        $this->postWebhook($payload)->assertOk();
        $renewedEnd = $manager->current($tenant)->current_period_ends_at->copy();
        $this->assertTrue($renewedEnd->greaterThan($initialEnd));
        $this->assertSame(SubscriptionPayment::CONFIRMED, $payment->fresh()->status);

        $this->postWebhook($payload)->assertOk();
        $this->assertTrue($manager->current($tenant)->current_period_ends_at->equalTo($renewedEnd));

        $tampered = $payload;
        $tampered['amount']['amount']++;
        $this->postWebhook($tampered, 'invalid-signature')->assertUnauthorized();
    }

    public function test_a_signed_but_inconsistent_webhook_cannot_confirm_the_payment(): void
    {
        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $payment = $this->pendingPayment($tenant, 'payreq-mismatch');
        $payload = $this->successfulPayload($payment, ['amount' => ['amount' => ($payment->amount * 100) + 100, 'currency' => 'XOF']]);

        $this->postWebhook($payload)->assertUnprocessable();
        $this->assertSame(SubscriptionPayment::PENDING, $payment->fresh()->status);
    }

    public function test_the_browser_return_never_confirms_a_payment(): void
    {
        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $payment = $this->pendingPayment($tenant, 'payreq-browser');

        $this->get(route('payments.jeko.success', ['reference' => $payment->metadata['merchant_reference']]))
            ->assertOk()
            ->assertSee('en cours de confirmation');

        $this->assertSame(SubscriptionPayment::PENDING, $payment->fresh()->status);
    }

    public function test_it_rejects_an_untrusted_checkout_url_and_marks_the_payment_failed(): void
    {
        Http::fake([
            'https://api.jeko.africa/partner_api/payment_requests' => Http::response([
                'id' => 'payreq-host',
                'redirectUrl' => 'https://example.org/collect-credentials',
            ], 201),
        ]);

        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);

        $this->assertThrows(
            fn () => app(SubscriptionBilling::class)->request($tenant, 'jeko', 'wave'),
            RuntimeException::class,
        );

        $payment = TenantContext::withoutTenancy(fn () => SubscriptionPayment::query()->latest('id')->firstOrFail());
        $this->assertSame(SubscriptionPayment::FAILED, $payment->status);
    }

    public function test_reconciliation_confirms_a_success_when_the_webhook_was_missed(): void
    {
        $tenant = $this->tenant();
        $manager = app(SubscriptionManager::class);
        $manager->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $payment = $this->pendingPayment($tenant, 'payreq-reconcile');
        $initialEnd = $manager->current($tenant)->current_period_ends_at->copy();

        Http::fake([
            'https://api.jeko.africa/partner_api/payment_requests/payreq-reconcile' => Http::response([
                'id' => 'payreq-reconcile',
                'storeId' => 'store-test-id',
                'reference' => $payment->metadata['merchant_reference'],
                'paymentMethod' => 'wave',
                'status' => 'success',
                'transaction' => [
                    'id' => 'txn-reconcile',
                    'amount' => ['amount' => $payment->amount * 100, 'currency' => 'XOF'],
                    'status' => 'success',
                ],
            ]),
        ]);

        $this->assertSame('confirmed', app(JekoPaymentReconciler::class)->reconcile($payment));
        $this->assertSame(SubscriptionPayment::CONFIRMED, $payment->fresh()->status);
        $this->assertTrue($manager->current($tenant)->current_period_ends_at->greaterThan($initialEnd));
    }

    public function test_reconciliation_records_a_failed_collection(): void
    {
        Notification::fake();
        $tenant = $this->tenant();
        $master = $this->userIn($tenant);
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $payment = $this->pendingPayment($tenant, 'payreq-error');

        Http::fake([
            'https://api.jeko.africa/partner_api/payment_requests/payreq-error' => Http::response([
                'id' => 'payreq-error',
                'status' => 'error',
                'errorReason' => 'insufficient_funds',
            ]),
        ]);

        $this->assertSame('failed', app(JekoPaymentReconciler::class)->reconcile($payment));
        $this->assertSame(SubscriptionPayment::FAILED, $payment->fresh()->status);
        $this->assertCount(1, Notification::sent($master, PaymentFailed::class), 'le master est prévenu de l\'échec');
    }

    public function test_the_api_key_is_never_sent_over_plain_http_outside_local_and_test(): void
    {
        config()->set('services.jeko.base_url', 'http://api.jeko.africa');
        $this->app['env'] = 'production';
        Http::fake();
        $tenant = $this->tenant();
        $subscription = app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);

        $this->assertThrows(
            fn () => app(JekoSubscriptionPaymentGateway::class)->initiate($subscription, 10000, ['payment_method' => 'wave']),
            RuntimeException::class,
            'base_url doit être en HTTPS'
        );
        Http::assertNothingSent();
    }

    public function test_a_plan_change_cannot_be_cancelled_while_its_jeko_checkout_can_still_be_paid(): void
    {
        Http::fake([
            'https://api.jeko.africa/partner_api/payment_requests' => Http::response([
                'id' => 'payreq-plan-change',
                'status' => 'pending',
                'redirectUrl' => 'https://pay.jeko.africa/pay_request/pr/payreq-plan-change',
            ], 201),
            'https://api.jeko.africa/partner_api/payment_requests/payreq-plan-change' => Http::response([
                'id' => 'payreq-plan-change',
                'status' => 'error',
                'errorReason' => 'cancelled_by_customer',
            ]),
        ]);

        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $billing = app(SubscriptionBilling::class);
        $request = $billing->requestChange($tenant, $this->plan('entreprise'), SubscriptionCycle::Monthly, null, 'jeko', 'wave');

        $this->assertThrows(
            fn () => $billing->cancelPendingRequests($tenant),
            InvalidSubscriptionTransition::class,
        );

        $this->assertSame(SubscriptionChangeRequest::PENDING, $request->fresh()->status);
        $payment = TenantContext::withoutTenancy(fn () => SubscriptionPayment::query()->where('tenant_id', $tenant->id)->latest('id')->firstOrFail());
        $this->assertSame(SubscriptionPayment::PENDING, $payment->status);
        $this->assertSame('payreq-plan-change', $payment->provider_reference);

        $this->assertSame('failed', app(JekoPaymentReconciler::class)->reconcile($payment));
        $billing->cancelPendingRequests($tenant);

        $this->assertSame(SubscriptionPayment::FAILED, $payment->fresh()->status);
        $this->assertSame(SubscriptionChangeRequest::CANCELLED, $request->fresh()->status);
    }

    public function test_a_prorated_plan_change_keeps_the_exact_fcfa_amount_for_jeko(): void
    {
        $this->travelToDate('2026-10-10 10:00:00');
        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $this->travelToDate('2026-10-25 10:00:00');

        Http::fake([
            'https://api.jeko.africa/partner_api/payment_requests' => Http::response([
                'id' => 'payreq-rounded',
                'status' => 'pending',
                'redirectUrl' => 'https://pay.jeko.africa/pay_request/pr/payreq-rounded',
            ], 201),
        ]);

        $request = app(SubscriptionBilling::class)->requestChange(
            $tenant,
            $this->plan('entreprise'),
            SubscriptionCycle::Monthly,
            null,
            'jeko',
            'wave',
        );
        $payment = TenantContext::withoutTenancy(fn () => SubscriptionPayment::query()->where('tenant_id', $tenant->id)->latest('id')->firstOrFail());

        $this->assertSame(15484, $request->quote['amount_due']);
        $this->assertSame(15484, $payment->amount);
        Http::assertSent(fn (Request $sent): bool => $sent['amountCents'] === 1548400);
    }

    public function test_a_plan_change_is_cancelled_when_jeko_cannot_create_its_checkout(): void
    {
        Http::fake([
            'https://api.jeko.africa/partner_api/payment_requests' => Http::response([
                'id' => 'payreq-invalid',
                'redirectUrl' => 'https://example.org/not-jeko',
            ], 201),
        ]);

        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);

        $this->assertThrows(
            fn () => app(SubscriptionBilling::class)->requestChange(
                $tenant,
                $this->plan('entreprise'),
                SubscriptionCycle::Monthly,
                null,
                'jeko',
                'wave',
            ),
            RuntimeException::class,
        );

        $request = TenantContext::withoutTenancy(fn () => SubscriptionChangeRequest::query()->where('tenant_id', $tenant->id)->latest('id')->firstOrFail());
        $payment = TenantContext::withoutTenancy(fn () => SubscriptionPayment::query()->where('tenant_id', $tenant->id)->latest('id')->firstOrFail());

        $this->assertSame(SubscriptionChangeRequest::CANCELLED, $request->status);
        $this->assertSame(SubscriptionPayment::FAILED, $payment->status);
    }

    public function test_jeko_is_the_default_gateway_and_manual_must_be_asked_for_explicitly(): void
    {
        config()->set('subscriptions', array_diff_key(config('subscriptions'), ['default_gateway' => true]));

        $billing = app(SubscriptionBilling::class);

        $this->assertInstanceOf(JekoSubscriptionPaymentGateway::class, $billing->gateway());
        $this->assertSame('manual', $billing->gateway('manual')->name());
    }

    public function test_the_super_admin_can_still_record_an_offline_payment_while_jeko_is_the_default(): void
    {
        config()->set('subscriptions.default_gateway', 'jeko');
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        $this->actingAs($this->superAdmin());
        $tenant = $this->tenant();
        $this->userIn($tenant);
        $manager = app(SubscriptionManager::class);
        $manager->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $before = $manager->current($tenant)->current_period_ends_at;

        Livewire::test(ListTenantSubscriptions::class)
            ->callTableAction('payment', $manager->current($tenant), data: ['reference' => 'VIR-1']);

        $payment = TenantContext::runAs($tenant->id, fn () => SubscriptionPayment::query()->latest('id')->first());
        $this->assertSame('manual', $payment->gateway);
        $this->assertSame(SubscriptionPayment::CONFIRMED, $payment->status);
        $this->assertTrue($manager->current($tenant)->current_period_ends_at->greaterThan($before));
    }

    public function test_a_master_cannot_pay_the_pending_jeko_checkout_of_another_company(): void
    {
        $manager = app(SubscriptionManager::class);
        $mine = $this->tenant('Ma société');
        $other = $this->tenant('Autre société');
        $manager->activate($mine, $this->plan('pro'), SubscriptionCycle::Monthly);
        $manager->activate($other, $this->plan('pro'), SubscriptionCycle::Monthly);
        $foreign = $this->pendingPayment($other, 'payreq-foreign');
        $foreign->forceFill(['metadata' => ['redirect_url' => 'https://pay.jeko.africa/pay_request/pr/payreq-foreign']])->save();

        $this->actingAs($this->userIn($mine));

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(Show::class)->call('pay', $foreign->id);
    }

    public function test_the_payment_page_opens_inside_the_app_instead_of_redirecting_the_webview(): void
    {
        config()->set('subscriptions.default_gateway', 'jeko');
        Http::fake(['https://api.jeko.africa/*' => Http::response([
            'id' => 'payreq-in-app',
            'redirectUrl' => 'https://pay.jeko.africa/pay_request/pr/payreq-in-app',
        ])]);
        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $this->actingAs($this->userIn($tenant));

        $component = Livewire::test(Show::class)
            ->set('paymentMethod', 'orange')
            ->call('renew')
            ->assertNoRedirect();

        $scripts = implode(' ', array_map(fn ($script) => is_array($script) ? ($script['expression'] ?? '') : (string) $script, $component->effects['xjs'] ?? []));
        $this->assertStringContainsString('window.uvmasterOpenExternal', $scripts, 'ouverture par le pont natif (onglet intégré à l\'appli)');
        $this->assertStringContainsString('https://pay.jeko.africa/pay_request/pr/payreq-in-app', $scripts);
    }

    public function test_the_mobile_checkout_requires_an_explicit_payment_method(): void
    {
        config()->set('subscriptions.default_gateway', 'jeko');
        Http::fake();
        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $this->actingAs($this->userIn($tenant));

        Livewire::test(Show::class)
            ->call('renew')
            ->assertSet('error', 'Choisissez votre moyen de paiement.')
            ->assertDispatched('m-toast');

        Http::assertNothingSent();
        $this->assertSame([], TenantContext::runAs($tenant->id, fn () => SubscriptionPayment::query()->pluck('status')->all()));
    }

    public function test_a_one_shot_message_does_not_survive_the_next_refresh_of_the_page(): void
    {
        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $this->actingAs($this->userIn($tenant));

        Livewire::test(Show::class)
            ->call('requestChange', $this->plan('essentiel')->id, 'monthly')
            ->assertSet('message', 'Votre offre a été changée.')
            ->call('$refresh')
            ->assertSet('message', null)
            ->assertSet('error', null);
    }

    public function test_a_jeko_outage_shows_a_clear_message_reports_the_error_and_leaves_no_pending_payment(): void
    {
        config()->set('subscriptions.default_gateway', 'jeko');
        Http::fake(['https://api.jeko.africa/*' => Http::response(['message' => 'boom'], 503)]);
        $tenant = $this->tenant();
        app(SubscriptionManager::class)->activate($tenant, $this->plan('pro'), SubscriptionCycle::Monthly);
        $this->actingAs($this->userIn($tenant));

        Livewire::test(Show::class)
            ->set('paymentMethod', 'wave')
            ->call('renew')
            ->assertSet('error', 'Le service Jèko ne répond pas. Réessayez dans quelques instants.')
            ->assertDispatched('m-toast');

        $this->assertSame(
            [SubscriptionPayment::FAILED],
            TenantContext::runAs($tenant->id, fn () => SubscriptionPayment::query()->pluck('status')->all())
        );
    }

    private function pendingPayment($tenant, string $providerReference): SubscriptionPayment
    {
        return TenantContext::runAs((int) $tenant->id, fn () => SubscriptionPayment::create([
            'tenant_subscription_id' => app(SubscriptionManager::class)->current($tenant)->id,
            'amount' => 30000,
            'currency' => 'XOF',
            'status' => SubscriptionPayment::PENDING,
            'gateway' => 'jeko',
            'provider_reference' => $providerReference,
            'metadata' => [
                'purpose' => 'renewal',
                'merchant_reference' => 'SUB-'.$providerReference,
                'redirect_url' => 'https://pay.jeko.africa/pay_request/pr/'.$providerReference,
            ],
        ]));
    }

    /** @param array<string, mixed> $overrides */
    private function successfulPayload(SubscriptionPayment $payment, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'txn-'.$payment->provider_reference,
            'amount' => ['amount' => $payment->amount * 100, 'currency' => $payment->currency],
            'status' => 'success',
            'paymentMethod' => 'wave',
            'transactionType' => 'payment',
            'storeId' => 'store-test-id',
            'transactionDetails' => [
                'id' => $payment->provider_reference,
                'reference' => $payment->metadata['merchant_reference'],
            ],
        ], $overrides);
    }

    private function postWebhook(array $payload, ?string $signature = null)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/webhooks/jeko', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_JEKO_EVENT' => 'TRANSACTION_COMPLETED',
            'HTTP_JEKO_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body);
    }
}
