<?php

namespace App\Services\Subscriptions\Payments;

use App\Enums\JekoPaymentMethod;
use App\Models\SubscriptionPayment;
use App\Models\TenantSubscription;
use App\Tenancy\TenantContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class JekoSubscriptionPaymentGateway implements SubscriptionPaymentGateway
{
    public const NAME = 'jeko';

    public function name(): string
    {
        return self::NAME;
    }

    public function payableAmount(int $amount): int
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Le montant Jèko doit être positif.');
        }

        return max(100, intdiv($amount + 50, 100) * 100);
    }

    public function initiate(TenantSubscription $subscription, int $amount, array $metadata = []): SubscriptionPayment
    {
        $this->assertConfigured();

        if ($amount < 100 || $amount % 100 !== 0) {
            throw new InvalidArgumentException('Jèko exige un montant d’au moins 100 FCFA et multiple de 100.');
        }

        $paymentMethod = JekoPaymentMethod::tryFrom((string) ($metadata['payment_method'] ?? ''))
            ?? throw new InvalidArgumentException('Moyen de paiement Jèko invalide.');

        $payment = TenantContext::runAs((int) $subscription->tenant_id, fn () => SubscriptionPayment::create([
            'tenant_subscription_id' => $subscription->getKey(),
            'amount' => $amount,
            'currency' => 'XOF',
            'status' => SubscriptionPayment::PENDING,
            'gateway' => self::NAME,
            'metadata' => array_merge($metadata, [
                'payment_method' => $paymentMethod->value,
            ]),
        ]));

        $merchantReference = 'SUB-'.$payment->getKey().'-'.Str::upper(Str::random(12));

        try {
            $response = $this->client()->post('/partner_api/payment_requests', [
                'storeId' => config('services.jeko.store_id'),
                'amountCents' => $amount,
                'currency' => 'XOF',
                'reference' => $merchantReference,
                'paymentDetails' => [
                    'type' => 'redirect',
                    'data' => [
                        'paymentMethod' => $paymentMethod->value,
                        'successUrl' => $this->callbackUrl('payments.jeko.success', $merchantReference),
                        'errorUrl' => $this->callbackUrl('payments.jeko.error', $merchantReference),
                    ],
                ],
            ])->throw();

            $providerId = trim((string) $response->json('id'));
            $redirectUrl = trim((string) $response->json('redirectUrl'));

            if ($providerId === '' || ! $this->isAllowedRedirectUrl($redirectUrl)) {
                throw new RuntimeException('Réponse Jèko invalide : identifiant ou URL de paiement absent.');
            }

            return TenantContext::runAs((int) $subscription->tenant_id, function () use ($payment, $providerId, $redirectUrl, $merchantReference) {
                $payment->forceFill([
                    'provider_reference' => $providerId,
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'merchant_reference' => $merchantReference,
                        'redirect_url' => $redirectUrl,
                    ]),
                ])->save();

                return $payment->refresh();
            });
        } catch (Throwable $exception) {
            TenantContext::runAs((int) $subscription->tenant_id, function () use ($payment, $exception): void {
                $payment->forceFill([
                    'status' => SubscriptionPayment::FAILED,
                    'failure_reason' => Str::limit($exception->getMessage(), 255),
                ])->save();
            });

            throw new RuntimeException('Impossible d’initialiser le paiement Jèko.', previous: $exception);
        }
    }

    public function confirm(SubscriptionPayment $payment, array $proof): SubscriptionPayment
    {
        $details = $proof['transactionDetails'] ?? [];
        $amount = $proof['amount'] ?? [];

        $valid = ($proof['status'] ?? null) === 'success'
            && ($proof['transactionType'] ?? null) === 'payment'
            && ($proof['storeId'] ?? null) === config('services.jeko.store_id')
            && ($details['id'] ?? null) === $payment->provider_reference
            && ($details['reference'] ?? null) === ($payment->metadata['merchant_reference'] ?? null)
            && ($amount['amount'] ?? null) === $payment->amount
            && ($amount['currency'] ?? null) === $payment->currency
            && is_string($proof['id'] ?? null)
            && trim($proof['id']) !== '';

        if (! $valid) {
            throw new InvalidArgumentException('La confirmation Jèko ne correspond pas au paiement attendu.');
        }

        return TenantContext::runAs((int) $payment->tenant_id, function () use ($payment, $proof) {
            $payment->forceFill([
                'status' => SubscriptionPayment::CONFIRMED,
                'confirmed_at' => now(),
                'failure_reason' => null,
                'metadata' => array_merge($payment->metadata ?? [], [
                    'transaction_id' => $proof['id'],
                    'payment_method' => $proof['paymentMethod'] ?? ($payment->metadata['payment_method'] ?? null),
                ]),
            ])->save();

            return $payment;
        });
    }

    public function fail(SubscriptionPayment $payment, string $reason): SubscriptionPayment
    {
        return TenantContext::runAs((int) $payment->tenant_id, function () use ($payment, $reason) {
            $payment->forceFill([
                'status' => SubscriptionPayment::FAILED,
                'failure_reason' => Str::limit($reason, 255),
            ])->save();

            return $payment;
        });
    }

    public function fetch(SubscriptionPayment $payment): array
    {
        $this->assertConfigured();

        if ($payment->gateway !== self::NAME || blank($payment->provider_reference)) {
            throw new InvalidArgumentException('Ce paiement ne possède pas de demande Jèko vérifiable.');
        }

        return $this->client()
            ->get('/partner_api/payment_requests/'.rawurlencode($payment->provider_reference))
            ->throw()
            ->json();
    }

    public function checkoutUrl(SubscriptionPayment $payment): ?string
    {
        if ($payment->gateway !== self::NAME || $payment->status !== SubscriptionPayment::PENDING) {
            return null;
        }

        $url = $payment->metadata['redirect_url'] ?? null;

        return is_string($url) && $this->isAllowedRedirectUrl($url) ? $url : null;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.jeko.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-API-KEY' => config('services.jeko.api_key'),
                'X-API-KEY-ID' => config('services.jeko.api_key_id'),
            ])
            ->connectTimeout(5)
            ->timeout(15);
    }

    private function assertConfigured(): void
    {
        foreach (['base_url', 'api_key', 'api_key_id', 'store_id'] as $key) {
            if (blank(config("services.jeko.{$key}"))) {
                throw new RuntimeException("Configuration Jèko incomplète : services.jeko.{$key}.");
            }
        }

        // Les clés API ne doivent jamais circuler en clair : le HTTP n'est toléré qu'en local et en test.
        if (! str_starts_with((string) config('services.jeko.base_url'), 'https://') && ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Configuration Jèko invalide : services.jeko.base_url doit être en HTTPS.');
        }
    }

    private function isAllowedRedirectUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return $scheme === 'https' && is_string($host)
            && in_array(strtolower($host), config('services.jeko.redirect_hosts', []), true);
    }

    private function callbackUrl(string $routeName, string $reference): string
    {
        return rtrim((string) config('app.url'), '/').route($routeName, ['reference' => $reference], false);
    }
}
