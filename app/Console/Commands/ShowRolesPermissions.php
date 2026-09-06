<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class ShowRolesPermissions extends Command
{
    protected $signature = 'roles:show {role?}';

    protected $description = 'Afficher les rôles et leurs permissions';

    public function handle()
    {
        $roleName = $this->argument('role');

        if ($roleName) {
            $this->showRoleDetails($roleName);
        } else {
            $this->showAllRoles();
        }

        return 0;
    }

    private function showAllRoles()
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 RÔLES ET PERMISSIONS - SYSTÈME UV');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        $roles = Role::with('permissions')->get();

        foreach ($roles as $role) {
            $icon = match ($role->name) {
                'master' => '👑',
                'admin' => '⚙️',
                'comptable' => '💰',
                'commercial' => '👥',
                default => '📋',
            };

            $description = match ($role->name) {
                'master' => 'Accès total (toutes permissions)',
                'admin' => 'Tout sauf gestion des rôles',
                'comptable' => 'Finances, remboursements, commissions',
                'commercial' => 'Agents, transactions UV, fournisseurs',
                default => 'Rôle personnalisé',
            };

            $this->line("{$icon} <fg=cyan;options=bold>{$role->name}</> ({$role->permissions->count()} permissions)");
            $this->line("   {$description}");
            $this->newLine();
        }

        $this->info('💡 Pour voir les détails d\'un rôle : <fg=yellow>php artisan roles:show {role}</>');
        $this->newLine();

        $this->table(
            ['Rôle', 'Permissions', 'Description'],
            $roles->map(fn ($role) => [
                $role->name,
                $role->permissions->count(),
                match ($role->name) {
                    'master' => '✅ ACCÈS TOTAL',
                    'admin' => '✅ Tout sauf rôles',
                    'comptable' => '💰 Finances',
                    'commercial' => '👥 Agents/Transactions',
                    default => 'Personnalisé',
                },
            ])
        );
    }

    private function showRoleDetails($roleName)
    {
        $role = Role::where('name', $roleName)->with('permissions')->first();

        if (! $role) {
            $this->error("❌ Rôle '{$roleName}' introuvable");
            $this->warn('Rôles disponibles : '.Role::pluck('name')->implode(', '));

            return;
        }

        $icon = match ($role->name) {
            'master' => '👑',
            'admin' => '⚙️',
            'comptable' => '💰',
            'commercial' => '👥',
            default => '📋',
        };

        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info("{$icon} RÔLE : ".strtoupper($role->name));
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        $this->line('📊 <fg=cyan>Nombre de permissions :</> '.$role->permissions->count());
        $this->newLine();

        // Grouper les permissions par catégorie
        $grouped = $role->permissions->groupBy(function ($permission) {
            $parts = explode('_', $permission->name);

            return $parts[0];
        });

        foreach ($grouped as $category => $permissions) {
            $categoryName = match ($category) {
                'view' => '👁️  VUE',
                'create' => '➕ CRÉATION',
                'edit' => '✏️  MODIFICATION',
                'delete' => '🗑️  SUPPRESSION',
                'export' => '📤 EXPORT',
                'manage' => '⚙️  GESTION',
                'access' => '🔑 ACCÈS',
                'reconcile' => '💼 RÉCONCILIATION',
                default => '📋 '.strtoupper($category),
            };

            $this->line("<fg=yellow>{$categoryName}</>");
            foreach ($permissions as $permission) {
                $this->line("   • {$permission->name}");
            }
            $this->newLine();
        }
    }
}
