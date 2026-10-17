<?php

namespace App\Services\Subscriptions\Payments;

use App\Models\SubscriptionPayment;
use App\Services\Subscriptions\SubscriptionBilling;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

class JekoPaymentReconciler
{
    public function __construct(
        private readonly JekoSubscriptionPaymentGateway $gateway,
        private readonly SubscriptionBilling $billing,
    ) {}

    /** @return array{checked: int, confirmed: int, failed: int, expired: int, pending: int, errors: int} */
    public function run(int $limit = 200): array
    {
        $report = ['checked' => 0, 'confirmed' => 0, 'failed' => 0, 'expired' => 0, 'pending' => 0, 'errors' => 0];
        $payments = TenantContext::withoutTenancy(fn () => SubscriptionPayment::query()
            ->where('gateway', JekoSubscriptionPaymentGateway::NAME)
            ->where('status', SubscriptionPayment::PENDING)
            ->whereNotNull('provider_reference')
            ->oldest('id')
            ->limit($limit)
            ->get());

        foreach ($payments as $payment) {
            $report['checked']++;

            try {
                $result = $this->reconcile($payment);
                $report[$result]++;
            } catch (Throwable $exception) {
                $report['errors']++;
                Log::warning('Échec de réconciliation d’un paiement Jèko.', [
                    'payment_id' => $payment->getKey(),
                    'tenant_id' => $payment->tenant_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $report;
    }

    /** @return 'confirmed'|'failed'|'expired'|'pending' */
    public function reconcile(SubscriptionPayment $payment): string
    {
        if ($payment->status !== SubscriptionPayment::PENDING) {
            return 'pending';
        }

        $state = $this->gateway->fetch($payment);
        $status = $state['status'] ?? null;

        if ($status === 'pending') {
            // Jèko n'a rien encaissé : au-delà du délai, la demande abandonnée est annulée. On ne le fait QUE sur une réponse
            // « pending » de Jèko (jamais sur une panne : un paiement peut avoir abouti), après avoir vérifié le statut.
            return $this->billing->isExpired($payment) && $this->billing->expire($payment) ? 'expired' : 'pending';
        }

        if ($status === 'error') {
            $this->billing->fail($payment, (string) ($state['errorReason'] ?? 'Paiement refusé par Jèko.'));

            return 'failed';
        }

        if ($status !== 'success' || ! is_array($state['transaction'] ?? null)) {
            throw new \RuntimeException('Statut de paiement Jèko non reconnu.');
        }

        $transaction = $state['transaction'];
        $this->billing->confirm($payment, [
            'id' => $transaction['id'] ?? null,
            'amount' => $transaction['amount'] ?? null,
            'status' => $transaction['status'] ?? null,
            'paymentMethod' => $state['paymentMethod'] ?? null,
            'transactionType' => 'payment',
            'storeId' => $state['storeId'] ?? null,
            'transactionDetails' => [
                'id' => $state['id'] ?? null,
                'reference' => $state['reference'] ?? null,
            ],
        ]);

        return 'confirmed';
    }
}
