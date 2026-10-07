<?php

namespace App\Services;

use App\Models\PushToken;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirebaseMessaging
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function send(PushToken $device, array $payload): void
    {
        $credentials = $this->credentials();
        $projectId = config('services.firebase.project_id') ?: ($credentials['project_id'] ?? null);

        if (! $projectId) {
            throw new RuntimeException('Firebase project ID is not configured.');
        }

        $response = Http::withToken($this->accessToken($credentials))
            ->acceptJson()
            ->timeout(10)
            ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($projectId).'/messages:send', [
                'message' => [
                    'token' => $device->token,
                    'notification' => [
                        'title' => $payload['title'],
                        'body' => $payload['body'],
                    ],
                    'data' => [
                        'url' => $payload['url'],
                        'type' => $payload['type'],
                    ],
                    'android' => [
                        'priority' => 'high',
                        'notification' => [
                            'channel_id' => 'debt-alerts-v2',
                            'sound' => 'modelec_alert',
                        ],
                    ],
                ],
            ]);

        if ($response->successful()) {
            $device->forceFill(['last_used_at' => now()])->save();

            return;
        }

        $errorCode = data_get($response->json(), 'error.details.0.errorCode');

        if ($errorCode === 'UNREGISTERED') {
            $device->delete();

            return;
        }

        throw new RequestException($response);
    }

    private function credentials(): array
    {
        $path = config('services.firebase.credentials');

        if (is_string($path) && $path !== '' && ! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Firebase service-account credentials are not configured or readable.');
        }

        $credentials = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (($credentials['type'] ?? null) !== 'service_account'
            || empty($credentials['client_email'])
            || empty($credentials['private_key'])) {
            throw new RuntimeException('Firebase service-account credentials are invalid.');
        }

        return $credentials;
    }

    private function accessToken(array $credentials): string
    {
        $cacheKey = 'firebase:access-token:'.sha1((string) $credentials['client_email']);

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($credentials): string {
            $issuedAt = time();
            $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $claims = $this->base64UrlEncode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => self::TOKEN_URL,
                'iat' => $issuedAt,
                'exp' => $issuedAt + 3600,
            ], JSON_THROW_ON_ERROR));
            $unsigned = $header.'.'.$claims;
            $privateKey = openssl_pkey_get_private($credentials['private_key']);

            if ($privateKey === false || ! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Could not sign Firebase service-account assertion.');
            }

            $response = Http::asForm()->acceptJson()->timeout(10)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned.'.'.$this->base64UrlEncode($signature),
            ]);
            $response->throw();
            $accessToken = $response->json('access_token');

            if (! is_string($accessToken) || $accessToken === '') {
                throw new RuntimeException('Firebase OAuth response did not contain an access token.');
            }

            return $accessToken;
        });
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
