<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Security\TwoFactorGate;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class TwoFactorChallengeController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private TwoFactorAuthService $twoFactorService,
        private TwoFactorGate $gate,
    ) {}

    /** Écran de saisie du code. */
    public function show(Request $request)
    {
        if (! Auth::check()) {
            return redirect()->route('filament.admin.auth.login');
        }

        $user = Auth::user();

        if (! $user->two_factor_enabled) {
            return $this->gate->mustEnrollNow($user)
                ? redirect()->route('security.2fa')
                : redirect()->intended($this->home($user));
        }

        if ($this->gate->isVerified($request, $user)) {
            return redirect()->intended($this->home($user));
        }

        return view('auth.two-factor-challenge');
    }

    /** Vérifie le code à 6 chiffres. */
    public function verify(Request $request)
    {
        $request->validate(['code' => 'required|string|size:6']);

        return $this->attempt($request, 'code', fn (User $user) => $this->twoFactorService->validateOtp($user, $request->code), 'Code invalide. Veuillez réessayer.');
    }

    /** Vérifie un code de récupération (usage unique). */
    public function verifyRecoveryCode(Request $request)
    {
        $request->validate(['recovery_code' => 'required|string']);

        return $this->attempt($request, 'recovery_code', function (User $user) use ($request) {
            if (! $this->twoFactorService->verifyRecoveryCode($user, $request->recovery_code)) {
                return false;
            }

            session()->flash('warning', 'Code de récupération utilisé. Il vous en reste '.count($user->fresh()->two_factor_recovery_codes ?? []).'.');

            return true;
        }, 'Code de récupération invalide.');
    }

    private function attempt(Request $request, string $field, callable $check, string $error)
    {
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('filament.admin.auth.login');
        }

        // Anti-force brute : 5 essais par 5 minutes pour un compte depuis une adresse IP.
        $key = '2fa|'.$user->getKey().'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return back()->withErrors([$field => 'Trop d\'essais. Réessayez dans '.RateLimiter::availableIn($key).' secondes.']);
        }

        if (! $check($user)) {
            RateLimiter::hit($key, 300);

            return back()->withErrors([$field => $error]);
        }

        RateLimiter::clear($key);
        $this->gate->markVerified($request, $user, $request->boolean('trust_device'));

        return redirect(session()->pull('2fa_intended_url', $this->home($user)));
    }

    private function home(User $user): string
    {
        return $user->isSuperAdmin() ? '/platform' : '/admin';
    }
}
