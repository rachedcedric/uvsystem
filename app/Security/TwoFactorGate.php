<?php

namespace App\Security;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Politique 2FA centralisée : qui doit l'avoir, depuis quand c'est obligatoire, et si la
 * session / l'appareil courant a déjà prouvé le second facteur.
 *
 * Verrouillage évité par construction :
 *  - maîtres/masters : bandeau de rappel tant que `security.authentication.2fa_enforce_from`
 *    n'est pas atteinte (vide = jamais bloquant) ;
 *  - jamais de blocage pour qui n'a pas la 2FA avant la date ; la page d'activation reste toujours
 *    accessible ; codes de récupération et réinitialisation par la plateforme en secours.
 */
class TwoFactorGate
{
    public const SESSION_KEY = '2fa_verified_user';

    public const COOKIE = '2fa_trusted';

    public function featureEnabled(): bool
    {
        return (bool) config('security.authentication.2fa_enabled', true);
    }

    /** Ce rôle est-il censé utiliser la 2FA ? */
    public function isRequiredFor(User $user): bool
    {
        return $this->featureEnabled()
            && ($user->isSuperAdmin() || $user->hasAnyRole(config('security.authentication.2fa_required_for_roles', ['master', 'admin'])));
    }

    public function enforceFrom(): ?CarbonImmutable
    {
        $date = config('security.authentication.2fa_enforce_from');

        return $date ? CarbonImmutable::parse($date)->startOfDay() : null;
    }

    /** La 2FA est-elle OBLIGATOIRE maintenant pour cet utilisateur (et il ne l'a pas encore) ? */
    public function mustEnrollNow(User $user): bool
    {
        if (! $this->isRequiredFor($user) || $user->hasTwoFactorEnabled()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $from = $this->enforceFrom();

        return $from !== null && $from->isPast();
    }

    /** Jours restants avant l'obligation (null : pas de date, ou déjà obligatoire / non concerné). */
    public function reminderDaysLeft(User $user): int|false|null
    {
        if (! $this->isRequiredFor($user) || $user->hasTwoFactorEnabled() || $this->mustEnrollNow($user)) {
            return false; // pas de rappel
        }

        $from = $this->enforceFrom();

        return $from ? max(0, (int) ceil(now()->diffInDays($from, false))) : null;
    }

    public function needsReminder(User $user): bool
    {
        return $this->reminderDaysLeft($user) !== false;
    }

    /** L'utilisateur peut-il désactiver sa 2FA ? Non si son rôle l'impose et que l'obligation est en vigueur. */
    public function mayDisable(User $user): bool
    {
        if (! $this->isRequiredFor($user)) {
            return true;
        }

        if ($user->isSuperAdmin()) {
            return false;
        }

        return ! ($this->enforceFrom()?->isPast() ?? false);
    }

    public function isVerified(Request $request, User $user): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return true;
        }

        if ($request->session()->get(self::SESSION_KEY) === $user->getKey()) {
            return true;
        }

        if ($this->hasValidTrustedDevice($request, $user)) {
            $request->session()->put(self::SESSION_KEY, $user->getKey());

            return true;
        }

        return false;
    }

    public function markVerified(Request $request, User $user, bool $trustDevice = false): void
    {
        $request->session()->put(self::SESSION_KEY, $user->getKey());
        $request->session()->put('2fa_verified_at', now());

        if ($trustDevice) {
            $days = max(1, (int) config('security.authentication.2fa_trusted_device_days', 30));
            Cookie::queue(Cookie::make(self::COOKIE, $this->trustToken($user, now()->addDays($days)->timestamp), $days * 1440, '/', null, $request->isSecure(), true, false, 'lax'));
        }
    }

    public function forget(Request $request): void
    {
        $request->session()->forget([self::SESSION_KEY, '2fa_verified_at']);
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    private function hasValidTrustedDevice(Request $request, User $user): bool
    {
        $token = (string) $request->cookie(self::COOKIE);
        [$id, $expires, $signature] = array_pad(explode('|', $token, 3), 3, null);

        if ((int) $id !== (int) $user->getKey() || (int) $expires < now()->timestamp) {
            return false;
        }

        return hash_equals($this->signature($user, (int) $expires), (string) $signature);
    }

    private function trustToken(User $user, int $expires): string
    {
        return $user->getKey().'|'.$expires.'|'.$this->signature($user, $expires);
    }

    /** Liée au secret 2FA : désactiver ou réinitialiser la 2FA invalide tous les appareils de confiance. */
    private function signature(User $user, int $expires): string
    {
        return hash_hmac('sha256', $user->getKey().'|'.$expires.'|'.sha1((string) $user->two_factor_secret), (string) config('app.key'));
    }
}
