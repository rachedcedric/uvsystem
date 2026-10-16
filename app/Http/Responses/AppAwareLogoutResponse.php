<?php

namespace App\Http\Responses;

use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LogoutResponse as Responsable;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Après une déconnexion depuis une page Filament / de sécurité, l'application Android
 * (agent utilisateur « UvmasterApp ») retrouve sa connexion mobile et non la page bureau.
 */
class AppAwareLogoutResponse implements Responsable
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        if (str_contains((string) $request->userAgent(), 'UvmasterApp')) {
            return redirect()->route('m.login');
        }

        return redirect()->to(
            Filament::hasLogin() ? Filament::getLoginUrl() : Filament::getUrl(),
        );
    }
}
