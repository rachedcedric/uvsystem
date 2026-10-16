<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\TwoFactorAuthService;
use Filament\Actions\Action;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Illuminate\Support\Facades\Auth;

class TwoFactorChallenge extends SimplePage
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static string $view = 'filament.pages.auth.two-factor-challenge';

    public ?string $code = '';

    public ?string $recovery_code = '';

    public bool $useRecoveryCode = false;

    public function mount(): void
    {
        // Si pas de user connecté ou pas de 2FA activé, rediriger
        $user = Auth::user();

        if (! $user || ! $user->two_factor_enabled) {
            redirect()->intended(route('filament.admin.pages.dashboard'));
        }

        // Si déjà vérifié, rediriger
        if (session()->has('2fa_verified')) {
            redirect()->intended(route('filament.admin.pages.dashboard'));
        }
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Vérification en deux étapes')
                    ->description('Entrez le code de vérification généré par votre application d\'authentification.')
                    ->schema([
                        TextInput::make('code')
                            ->label('Code de vérification')
                            ->placeholder('000000')
                            ->numeric()
                            ->length(6)
                            ->required()
                            ->autofocus()
                            ->visible(fn () => ! $this->useRecoveryCode),

                        TextInput::make('recovery_code')
                            ->label('Code de récupération')
                            ->placeholder('XXXX-XXXX-XXXX')
                            ->required()
                            ->visible(fn () => $this->useRecoveryCode),
                    ])
                    ->footerActions([
                        FormAction::make('toggleRecovery')
                            ->label(fn () => $this->useRecoveryCode ? 'Utiliser un code de vérification' : 'Utiliser un code de récupération')
                            ->link()
                            ->action(function () {
                                $this->useRecoveryCode = ! $this->useRecoveryCode;
                                $this->code = '';
                                $this->recovery_code = '';
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('verify')
                ->label('Vérifier')
                ->submit('verify')
                ->color('primary'),
        ];
    }

    public function verify(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user) {
            redirect()->route('filament.admin.auth.login');

            return;
        }

        $service = app(TwoFactorAuthService::class);

        if ($this->useRecoveryCode) {
            // Vérifier le code de récupération
            $this->validate([
                'recovery_code' => 'required|string',
            ]);

            if ($service->verifyRecoveryCode($user, $this->recovery_code)) {
                session()->put('2fa_verified', true);
                session()->put('2fa_verified_at', now());

                // Notification si peu de codes restants
                $remaining = count($user->fresh()->two_factor_recovery_codes ?? []);
                if ($remaining <= 3) {
                    Notification::make()
                        ->warning()
                        ->title('Codes de récupération faibles')
                        ->body("Il vous reste seulement {$remaining} codes de récupération. Régénérez-les dans votre profil.")
                        ->persistent()
                        ->send();
                }

                Notification::make()
                    ->success()
                    ->title('Authentification réussie')
                    ->body('Vous êtes maintenant connecté(e).')
                    ->send();

                redirect()->intended(route('filament.admin.pages.dashboard'));

                return;
            } else {
                Notification::make()
                    ->danger()
                    ->title('Code invalide')
                    ->body('Le code de récupération est invalide ou déjà utilisé.')
                    ->send();

                $this->recovery_code = '';

                return;
            }
        } else {
            // Vérifier le code OTP
            $this->validate([
                'code' => 'required|digits:6',
            ]);

            if ($service->validateOtp($user, $this->code)) {
                session()->put('2fa_verified', true);
                session()->put('2fa_verified_at', now());

                Notification::make()
                    ->success()
                    ->title('Authentification réussie')
                    ->body('Vous êtes maintenant connecté(e).')
                    ->send();

                redirect()->intended(route('filament.admin.pages.dashboard'));

                return;
            } else {
                Notification::make()
                    ->danger()
                    ->title('Code invalide')
                    ->body('Le code de vérification est incorrect. Veuillez réessayer.')
                    ->send();

                $this->code = '';

                return;
            }
        }
    }

    public function logout(): void
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirect(
            str_contains((string) request()->userAgent(), 'UvmasterApp')
                ? route('m.login')
                : route('filament.admin.auth.login'),
        );
    }

    public function hasLogo(): bool
    {
        return true;
    }

    public function getHeading(): string
    {
        return 'Vérification en deux étapes';
    }

    public function getSubheading(): ?string
    {
        return 'Authentification à double facteur activée';
    }
}
