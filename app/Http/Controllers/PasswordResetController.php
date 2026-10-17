<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\PasswordPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * Mot de passe oublié. Réponse identique que le compte existe ou non (pas d'énumération de comptes) ;
 * un mot de passe réinitialisé coupe toutes les sessions et tous les tokens du compte. La 2FA reste
 * exigée à la connexion suivante : le lien par e-mail ne la contourne jamais.
 */
class PasswordResetController extends Controller
{
    public function request()
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request)
    {
        $request->validate(['email' => 'required|email|max:255']);

        Password::sendResetLink($request->only('email'));

        return back()->with('status', 'Si un compte correspond à cette adresse, un lien de réinitialisation vient d\'être envoyé.');
    }

    public function reset(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email', '')]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->revokeAllAccess();

                event(new PasswordReset($user));
                TenantContext::runAs($user->tenant_id, fn () => activity()->causedBy($user)->performedOn($user)->log('Mot de passe réinitialisé par e-mail'));
            }
        );

        return $status === Password::PasswordReset
            ? redirect('/m/login')->with('status', 'Mot de passe modifié. Connectez-vous avec le nouveau.')
            : back()->withInput($request->only('email'))->withErrors(['email' => 'Lien invalide ou expiré. Refaites une demande.']);
    }
}
