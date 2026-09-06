<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class FixUserPermissions extends Command
{
    protected $signature = 'users:fix-permissions {email? : Email de l\'utilisateur à promouvoir master}';

    protected $description = 'Répare les permissions et assigne le rôle master à un utilisateur';

    public function handle(): int
    {
        // Reset cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->info('✅ Cache des permissions vidé');

        // Vérifier les rôles
        $rolesCount = Role::count();
        $permissionsCount = Permission::count();

        $this->info("📊 État actuel: {$rolesCount} rôles, {$permissionsCount} permissions");

        if ($rolesCount === 0 || $permissionsCount === 0) {
            $this->warn('⚠️  Aucun rôle ou permission trouvé. Exécution du seeder...');
            $this->call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
        }

        // Assigner rôle master si email fourni
        $email = $this->argument('email');

        if (!$email) {
            $email = $this->ask('Email de l\'utilisateur à promouvoir master');
        }

        if ($email) {
            $user = User::where('email', $email)->first();

            if (!$user) {
                $this->error("❌ Utilisateur avec email '{$email}' non trouvé");
                return 1;
            }

            // Assigner le rôle master
            $user->syncRoles(['master']);

            $this->info("✅ Utilisateur {$user->name} ({$user->email}) a maintenant le rôle MASTER");
            $this->info("🔑 Permissions: " . $user->getAllPermissions()->count());
        }

        // Afficher tous les utilisateurs avec leurs rôles
        $this->newLine();
        $this->info('📋 Liste des utilisateurs et leurs rôles:');

        $users = User::with('roles')->get();
        $this->table(
            ['ID', 'Nom', 'Email', 'Rôles'],
            $users->map(fn ($u) => [
                $u->id,
                $u->name,
                $u->email,
                $u->roles->pluck('name')->implode(', ') ?: 'Aucun rôle',
            ])
        );

        return 0;
    }
}
