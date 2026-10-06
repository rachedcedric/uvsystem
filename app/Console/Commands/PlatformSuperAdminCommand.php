<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class PlatformSuperAdminCommand extends Command
{
    protected $signature = 'platform:super-admin {email} {--name=Super Admin} {--password= : Sinon demandé de façon masquée}';

    protected $description = 'Crée (ou remet à jour) le super admin de la plateforme SaaS, hors de tout tenant';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $password = $this->option('password') ?: $this->secret('Mot de passe (12 caractères minimum)');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen((string) $password) < 12) {
            $this->error('E-mail invalide ou mot de passe trop court (12 caractères minimum).');

            return self::FAILURE;
        }

        Role::findOrCreate(User::ROLE_SUPER_ADMIN, 'web');

        // Contexte « plateforme » explicite : aucun tenant, le compte n'en reçoit donc aucun.
        $user = TenantContext::runAs(null, function () use ($email, $password) {
            $user = User::withoutGlobalScopes()->where('email', $email)->first();

            if ($user && $user->tenant_id !== null) {
                $this->error("{$email} appartient à un tenant : un super admin ne peut pas être membre d'un tenant.");

                return null;
            }

            $user ??= new User(['email' => $email]);
            $user->fill(['name' => $this->option('name'), 'password' => Hash::make($password)])->save();
            $user->syncRoles([User::ROLE_SUPER_ADMIN]);

            return $user;
        });

        if (! $user) {
            return self::FAILURE;
        }

        $this->info("Super admin prêt : {$email} → /platform");

        return self::SUCCESS;
    }
}
