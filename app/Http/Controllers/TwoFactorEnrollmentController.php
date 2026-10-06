<?php

namespace App\Http\Controllers;

use App\Security\TwoFactorGate;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Activation / désactivation de la 2FA sur une page autonome (hors Filament) : elle fonctionne
 * pour tous les profils — tenant, mobile, super admin — et reste accessible même quand la 2FA
 * est obligatoire mais pas encore activée.
 */
class TwoFactorEnrollmentController extends Controller
{
    public function __construct(private TwoFactorAuthService $service, private TwoFactorGate $gate) {}

    public function show(Request $request)
    {
        $user = $request->user();
        $data = ['enabled' => $user->hasTwoFactorEnabled(), 'required' => $this->gate->mustEnrollNow($user), 'mayDisable' => $this->gate->mayDisable($user)];

        if (! $data['enabled']) {
            $secret = $request->session()->get('2fa_enroll_secret') ?: $this->service->generateSecretKey();
            $request->session()->put('2fa_enroll_secret', $secret);
            $data += ['secret' => $secret, 'qrCode' => $this->service->generateQrCodeSvg($user, $secret)];
        }

        return view('auth.two-factor-enroll', $data + ['recoveryCodes' => session('2fa_new_recovery_codes')]);
    }

    public function confirm(Request $request)
    {
        $request->validate(['code' => 'required|digits:6']);

        $user = $request->user();
        $secret = (string) $request->session()->get('2fa_enroll_secret');

        if ($user->hasTwoFactorEnabled() || $secret === '' || ! $this->service->enable($user, $secret, $request->code)) {
            return back()->withErrors(['code' => 'Code incorrect. Réessayez avec le code affiché dans votre application.']);
        }

        $request->session()->forget('2fa_enroll_secret');
        $this->gate->markVerified($request, $user->fresh(), true);

        // Les codes de récupération ne sont montrés qu'une fois, juste après l'activation.
        return redirect()->route('security.2fa')->with('2fa_new_recovery_codes', $user->fresh()->two_factor_recovery_codes);
    }

    public function disable(Request $request)
    {
        $request->validate(['password' => 'required|string']);

        $user = $request->user();

        abort_unless($this->gate->mayDisable($user), 403, 'Votre rôle impose la double authentification.');

        if (! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Mot de passe incorrect.']);
        }

        $this->service->disable($user);
        $this->gate->forget($request);

        return redirect()->route('security.2fa')->with('status', 'Double authentification désactivée.');
    }
}
