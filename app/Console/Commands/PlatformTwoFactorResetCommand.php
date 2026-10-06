<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactorAuthService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

class PlatformTwoFactorResetCommand extends Command
{
    protected $signature = 'platform:2fa-reset {email}';

    protected $description = 'Réinitialise la double authentification d\'un compte (téléphone perdu, plus de codes de récupération)';

    public function handle(): int
    {
        $user = TenantContext::withoutTenancy(fn () => User::where('email', strtolower(trim($this->argument('email'))))->first());

        if (! $user) {
            $this->error('Compte introuvable.');

            return self::FAILURE;
        }

        app(TwoFactorAuthService::class)->disable($user);
        $this->info("2FA réinitialisée pour {$user->email} : il devra la réactiver à sa prochaine connexion si son rôle l'exige.");

        return self::SUCCESS;
    }
}
