<?php

namespace App\Http\Controllers;

use App\Services\TwoFactorAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private TwoFactorAuthService $twoFactorService
    ) {}

    /**
     * Show the 2FA challenge form.
     */
    public function show()
    {
        if (! Auth::check()) {
            return redirect()->route('filament.admin.auth.login');
        }

        if (! Auth::user()->two_factor_enabled) {
            return redirect()->intended('/admin');
        }

        if (session()->has('2fa_verified')) {
            return redirect()->intended('/admin');
        }

        return view('auth.two-factor-challenge');
    }

    /**
     * Verify the 2FA code.
     */
    public function verify(Request $request)
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = Auth::user();

        if (! $user) {
            return redirect()->route('filament.admin.auth.login');
        }

        // Try to validate OTP
        if ($this->twoFactorService->validateOtp($user, $request->code)) {
            session()->put('2fa_verified', true);
            session()->put('2fa_verified_at', now());

            $intendedUrl = session()->pull('2fa_intended_url', '/admin');

            return redirect($intendedUrl);
        }

        return back()->withErrors(['code' => 'Code invalide. Veuillez réessayer.']);
    }

    /**
     * Verify using a recovery code.
     */
    public function verifyRecoveryCode(Request $request)
    {
        $request->validate([
            'recovery_code' => 'required|string',
        ]);

        $user = Auth::user();

        if (! $user) {
            return redirect()->route('filament.admin.auth.login');
        }

        if ($this->twoFactorService->verifyRecoveryCode($user, $request->recovery_code)) {
            session()->put('2fa_verified', true);
            session()->put('2fa_verified_at', now());

            $intendedUrl = session()->pull('2fa_intended_url', '/admin');

            session()->flash('warning', 'Vous avez utilisé un code de récupération. Il vous en reste '.count($user->two_factor_recovery_codes ?? []).'.');

            return redirect($intendedUrl);
        }

        return back()->withErrors(['recovery_code' => 'Code de récupération invalide.']);
    }
}
