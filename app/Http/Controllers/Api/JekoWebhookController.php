<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidSubscriptionTransition;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Services\Subscriptions\Payments\JekoSubscriptionPaymentGateway;
use App\Services\Subscriptions\Payments\JekoWebhookSignatureVerifier;
use App\Services\Subscriptions\SubscriptionBilling;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;

class JekoWebhookController extends Controller
{
    public function __invoke(Request $request, JekoWebhookSignatureVerifier $verifier, SubscriptionBilling $billing): JsonResponse
    {
        $rawBody = $request->getContent();
        $secret = (string) config('services.jeko.webhook_secret');

        if (! $verifier->verify($rawBody, $request->header('Jeko-Signature'), $secret)) {
            return response()->json(['message' => 'Signature invalide.'], 401);
        }

        if ($request->header('Jeko-Event') !== 'TRANSACTION_COMPLETED') {
            return response()->json(['received' => true, 'processed' => false]);
        }

        try {
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'Payload JSON invalide.'], 422);
        }

        if (($payload['transactionType'] ?? null) !== 'payment' || ($payload['status'] ?? null) !== 'success') {
            return response()->json(['received' => true, 'processed' => false]);
        }

        $providerId = $payload['transactionDetails']['id'] ?? null;
        if (! is_string($providerId) || $providerId === '') {
            return response()->json(['message' => 'Identifiant de demande absent.'], 422);
        }

        $payment = TenantContext::withoutTenancy(fn () => SubscriptionPayment::query()
            ->where('gateway', JekoSubscriptionPaymentGateway::NAME)
            ->where('provider_reference', $providerId)
            ->first());

        // Le webhook d'entreprise peut contenir d'autres encaissements du même magasin.
        if ($payment === null) {
            return response()->json(['received' => true, 'processed' => false]);
        }

        try {
            $billing->confirm($payment, $payload);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (InvalidSubscriptionTransition $exception) {
            $status = TenantContext::withoutTenancy(fn () => SubscriptionPayment::query()->whereKey($payment->getKey())->value('status'));

            // Jèko a encaissé un paiement que nous avions clos (expiré, annulé) : de l'argent a été pris sans effet sur
            // l'abonnement. À traiter à la main (remboursement ou activation) : on ne l'applique jamais automatiquement.
            if (in_array($status, [SubscriptionPayment::FAILED, SubscriptionPayment::CANCELLED], true)) {
                Log::critical('Paiement Jèko encaissé sur un paiement déjà clos : remboursement ou régularisation manuelle requise.', [
                    'payment_id' => $payment->getKey(),
                    'tenant_id' => $payment->tenant_id,
                    'provider_reference' => $payment->provider_reference,
                    'status' => $status,
                ]);
            }

            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['received' => true, 'processed' => true]);
    }
}
