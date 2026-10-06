<?php

namespace App\Livewire\Mobile\Admin;

use App\Services\TwoFactorAuthService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Double authentification du compte connecté (même service que l'interface complète).
 * Note : l'exigence du code à la connexion (middleware Require2FA) est
 * actuellement désactivée sur le panneau, comme sur l'interface mobile.
 */
#[Layout('components.layouts.mobile')]
#[Title('Sécurité du compte')]
class TwoFactor extends Component
{
    public ?string $secret = null;

    public ?string $qrCode = null;

    public string $code = '';

    public bool $showSetup = false;

    public function startSetup(): void
    {
        $service = app(TwoFactorAuthService::class);
        $this->secret = $service->generateSecretKey();
        $this->qrCode = $service->generateQrCodeSvg(auth()->user(), $this->secret);
        $this->showSetup = true;
        $this->code = '';
    }

    public function cancelSetup(): void
    {
        $this->reset(['secret', 'qrCode', 'code', 'showSetup']);
    }

    public function confirmSetup(): void
    {
        $this->validate(['code' => ['required', 'digits:6']], ['code.required' => 'Saisissez le code à 6 chiffres.', 'code.digits' => 'Le code contient 6 chiffres.']);

        if (! app(TwoFactorAuthService::class)->enable(auth()->user(), (string) $this->secret, $this->code)) {
            $this->addError('code', 'Code incorrect. Réessayez.');

            return;
        }

        $this->reset(['secret', 'qrCode', 'code', 'showSetup']);
        $this->dispatch('m-toast', message: 'Double authentification activée.');
    }

    public function disable(): void
    {
        app(TwoFactorAuthService::class)->disable(auth()->user());
        $this->dispatch('m-toast', message: 'Double authentification désactivée.');
    }

    public function regenerate(): void
    {
        auth()->user()->update(['two_factor_recovery_codes' => app(TwoFactorAuthService::class)->generateRecoveryCodes()]);
        $this->dispatch('m-toast', message: 'Nouveaux codes de récupération générés.');
    }

    public function render()
    {
        $user = auth()->user()->fresh();

        return view('livewire.mobile.admin.two-factor', [
            'enabled' => (bool) $user->two_factor_enabled,
            'recoveryCodes' => $user->two_factor_enabled ? (array) $user->two_factor_recovery_codes : [],
        ]);
    }
}
