<?php

namespace App\Services\Subscriptions\Payments;

class JekoWebhookSignatureVerifier
{
    public function verify(string $rawBody, ?string $signature, string $secret): bool
    {
        if ($rawBody === '' || $signature === null || $secret === '' || ! preg_match('/\A[a-f0-9]{64}\z/', $signature)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }
}
