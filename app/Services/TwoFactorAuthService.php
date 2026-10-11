<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthService
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA;
    }

    /**
     * Generate a new secret key for 2FA.
     */
    public function generateSecretKey(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * Generate a QR code SVG for the given user and secret.
     */
    public function generateQrCodeSvg(User $user, string $secret): string
    {
        $qrCodeUrl = $this->google2fa->getQRCodeUrl(
            config('branding.name'),
            $user->email,
            $secret
        );

        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd
        );

        $writer = new Writer($renderer);

        return $writer->writeString($qrCodeUrl);
    }

    /**
     * Generate recovery codes.
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(Str::random(4)).'-'.
                       strtoupper(Str::random(4)).'-'.
                       strtoupper(Str::random(4));
        }

        return $codes;
    }

    /**
     * Verify a 2FA code against the secret.
     */
    public function verify(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code);
    }

    /**
     * Enable 2FA for a user.
     */
    public function enable(User $user, string $secret, string $code): bool
    {
        if (! $this->verify($secret, $code)) {
            return false;
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $user->update([
            'two_factor_secret' => $secret,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes,
        ]);

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['action' => '2fa_enabled'])
            ->log('Authentification à deux facteurs activée');

        return true;
    }

    /**
     * Disable 2FA for a user.
     */
    public function disable(User $user): void
    {
        $user->update([
            'two_factor_secret' => null,
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ]);

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['action' => '2fa_disabled'])
            ->log('Authentification à deux facteurs désactivée');
    }

    /**
     * Verify a recovery code and remove it if valid.
     */
    public function verifyRecoveryCode(User $user, string $code): bool
    {
        $recoveryCodes = $user->two_factor_recovery_codes ?? [];

        // Normalize the code for comparison
        $normalizedCode = strtoupper(trim($code));

        $index = array_search($normalizedCode, $recoveryCodes, true);

        if ($index === false) {
            return false;
        }

        // Remove the used code
        unset($recoveryCodes[$index]);
        $user->update([
            'two_factor_recovery_codes' => array_values($recoveryCodes),
        ]);

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['action' => 'recovery_code_used', 'remaining' => count($recoveryCodes)])
            ->log('Code de récupération 2FA utilisé');

        return true;
    }

    /**
     * Check if 2FA is enabled for a user.
     */
    public function isEnabled(User $user): bool
    {
        return $user->two_factor_enabled && $user->two_factor_secret;
    }

    /**
     * Validate OTP code for a user.
     */
    public function validateOtp(User $user, string $code): bool
    {
        if (! $user->two_factor_secret) {
            return false;
        }

        // Refuse un code déjà utilisé (rejeu) : on retient le dernier pas de temps accepté par utilisateur.
        $cacheKey = '2fa_last_timestamp:'.$user->getKey();
        $timestamp = $this->google2fa->verifyKeyNewer($user->two_factor_secret, trim($code), Cache::get($cacheKey), 1);

        if ($timestamp === false) {
            return false;
        }

        // Sans horodatage précédent la bibliothèque renvoie `true` : on retient alors le pas de temps courant.
        Cache::put($cacheKey, $timestamp === true ? $this->google2fa->getTimestamp() : $timestamp, now()->addMinutes(5));

        return true;
    }

    /**
     * Regenerate recovery codes for a user.
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->update([
            'two_factor_recovery_codes' => $codes,
        ]);

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['action' => 'recovery_codes_regenerated'])
            ->log('Codes de récupération 2FA régénérés');

        return $codes;
    }
}
