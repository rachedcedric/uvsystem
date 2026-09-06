<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class AssignMasterRole extends Command
{
    protected $signature = 'admin:assign-master-role {email=leaudouce0@gmail.com}';

    protected $description = 'Assigner le rôle master à un utilisateur';

    public function handle()
    {
        $email = $this->argument('email');

        $this->info('🔐 ASSIGNATION RÔLE MASTER');
        $this->line('================================');
        $this->newLine();

        // 1. Trouver l'utilisateur
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("❌ Utilisateur non trouvé: {$email}");

            return 1;
        }
        $this->info("✅ Utilisateur trouvé: {$user->name}");
        $this->newLine();

        // 2. Vérifier/Créer le rôle master
        $this->info('🔍 Vérification du rôle "master"...');
        $masterRole = Role::where('name', 'master')->first();

        if (! $masterRole) {
            $this->warn('❌ Rôle "master" n\'existe pas');
            $this->line('🔄 Création du rôle...');
            $masterRole = Role::create([
                'name' => 'master',
                'guard_name' => 'web',
            ]);
            $this->info('✅ Rôle "master" créé');
        } else {
            $this->info("✅ Rôle \"master\" existe (ID: {$masterRole->id})");
        }
        $this->newLine();

        // 3. Assigner le rôle
        $this->info('🔄 Assignation du rôle...');
        if ($user->hasRole('master')) {
            $this->warn('⚠️  L\'utilisateur a déjà le rôle "master"');
        } else {
            $user->assignRole('master');
            $this->info('✅ Rôle "master" assigné avec succès');
        }
        $this->newLine();

        // 4. Vérification finale
        $this->info('📋 VÉRIFICATION FINALE');
        $roles = $user->getRoleNames();
        $this->line('Rôles de l\'utilisateur: '.$roles->implode(', '));
        $this->line('Permissions actives: '.($user->hasRole('master') ? 'Accès complet ✅' : 'Accès limité ⚠️'));

        $this->newLine();
        $this->line('================================');
        $this->info('✅ CONFIGURATION TERMINÉE');
        $this->line('================================');
        $this->line('Vous pouvez maintenant vous connecter:');
        $this->line("  Email    : {$email}");
        $this->line('  Password : Master@2025!');
        $this->line('  URL      : http://localhost:8000/admin/login');

        return 0;
    }
}
