<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\TwoFactorAuthService;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class TwoFactorAuthPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Sécurité 2FA';

    protected static ?string $title = 'Authentification à Deux Facteurs';

    protected static ?string $navigationGroup = 'Mon Compte';

    protected static ?int $navigationSort = 200;

    protected static string $view = 'filament.pages.two-factor-auth-page';

    public ?string $secret = null;

    public ?string $qrCode = null;

    public ?string $code = '';

    public bool $is2FAEnabled = false;

    public ?array $recoveryCodes = null;

    public bool $showSetup = false;

    protected TwoFactorAuthService $twoFactorService;

    public function boot(TwoFactorAuthService $twoFactorService): void
    {
        $this->twoFactorService = $twoFactorService;
    }

    /**
     * Get the authenticated user.
     */
    protected function getUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    public function mount(): void
    {
        $user = $this->getUser();
        $this->is2FAEnabled = $user->two_factor_enabled ?? false;

        // Les recovery codes sont déjà castés en array par Eloquent
        if ($this->is2FAEnabled && $user->two_factor_recovery_codes) {
            $this->recoveryCodes = $user->two_factor_recovery_codes;
        }
    }

    public function startSetup(): void
    {
        $service = app(TwoFactorAuthService::class);
        $this->secret = $service->generateSecretKey();
        $this->qrCode = $service->generateQrCodeSvg($this->getUser(), $this->secret);
        $this->showSetup = true;
    }

    public function confirmSetup(): void
    {
        $this->validate([
            'code' => 'required|digits:6',
        ]);

        $service = app(TwoFactorAuthService::class);

        if ($service->enable($this->getUser(), $this->secret, $this->code)) {
            $this->is2FAEnabled = true;
            $this->showSetup = false;

            // Les recovery codes sont déjà castés en array par Eloquent
            /** @var User $user */
            $user = $this->getUser()->fresh();
            $this->recoveryCodes = $user->two_factor_recovery_codes ?? [];

            Notification::make()
                ->title('2FA Activé!')
                ->body('L\'authentification à deux facteurs est maintenant active.')
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Code Invalide')
                ->body('Le code saisi est incorrect. Veuillez réessayer.')
                ->danger()
                ->send();
        }

        $this->code = '';
    }

    public function disable2FA(): void
    {
        abort_unless(app(\App\Security\TwoFactorGate::class)->mayDisable($this->getUser()), 403);

        $service = app(TwoFactorAuthService::class);
        $service->disable($this->getUser());

        $this->is2FAEnabled = false;
        $this->recoveryCodes = null;
        $this->secret = null;
        $this->qrCode = null;

        Notification::make()
            ->title('2FA Désactivé')
            ->body('L\'authentification à deux facteurs a été désactivée.')
            ->warning()
            ->send();
    }

    public function regenerateRecoveryCodes(): void
    {
        // Même format que le service (tableau de codes) : l'ancien encrypt(json_encode()) rendait les
        // codes illisibles pour la vérification de récupération.
        $newCodes = app(TwoFactorAuthService::class)->regenerateRecoveryCodes($this->getUser());

        $this->recoveryCodes = $newCodes;

        Notification::make()
            ->title('Codes régénérés')
            ->body('Nouveaux codes de récupération générés. Conservez-les en lieu sûr!')
            ->success()
            ->send();
    }

    public function cancelSetup(): void
    {
        $this->showSetup = false;
        $this->secret = null;
        $this->qrCode = null;
        $this->code = '';
    }
}
