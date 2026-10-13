<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ============================================
        // DÉFINITION DES PERMISSIONS
        // ============================================

        $permissions = [
            // === GESTION DES UTILISATEURS ===
            'view_users' => 'Voir les utilisateurs',
            'create_users' => 'Créer des utilisateurs',
            'edit_users' => 'Modifier des utilisateurs',
            'delete_users' => 'Supprimer des utilisateurs',

            // === GESTION DES RÔLES (Réservé Master) ===
            'view_roles' => 'Voir les rôles',
            'create_roles' => 'Créer des rôles',
            'edit_roles' => 'Modifier des rôles',
            'delete_roles' => 'Supprimer des rôles',
            'assign_roles' => 'Assigner des rôles',

            // === GESTION DES AGENTS ===
            'view_agents' => 'Voir les agents',
            'create_agents' => 'Créer des agents',
            'edit_agents' => 'Modifier des agents',
            'delete_agents' => 'Supprimer des agents',
            'export_agents' => 'Exporter les agents',

            // === GESTION DES FOURNISSEURS ===
            'view_fournisseurs' => 'Voir les fournisseurs',
            'create_fournisseurs' => 'Créer des fournisseurs',
            'edit_fournisseurs' => 'Modifier des fournisseurs',
            'delete_fournisseurs' => 'Supprimer des fournisseurs',

            // === GESTION DES APPROVISIONNEMENTS ===
            'view_approvisionnements' => 'Voir les approvisionnements',
            'create_approvisionnements' => 'Créer des approvisionnements',
            'edit_approvisionnements' => 'Modifier des approvisionnements',
            'delete_approvisionnements' => 'Supprimer des approvisionnements',

            // === TRANSACTIONS UV ===
            'view_uv_transactions' => 'Voir les transactions UV',
            'create_uv_transactions' => 'Créer des transactions UV',
            'edit_uv_transactions' => 'Modifier des transactions UV',
            'delete_uv_transactions' => 'Supprimer des transactions UV',
            'export_uv_transactions' => 'Exporter les transactions UV',

            // === REMBOURSEMENTS ===
            'view_remboursements' => 'Voir les remboursements',
            'create_remboursements' => 'Créer des remboursements',
            'edit_remboursements' => 'Modifier des remboursements',
            'delete_remboursements' => 'Supprimer des remboursements',
            'export_remboursements' => 'Exporter les remboursements',

            // === REMBOURSEMENTS FOURNISSEURS (dettes envers les fournisseurs Agent Premium) ===
            'view_remboursements_fournisseurs' => 'Voir les remboursements fournisseurs',
            'create_remboursements_fournisseurs' => 'Créer des remboursements fournisseurs',
            'edit_remboursements_fournisseurs' => 'Modifier des remboursements fournisseurs',
            'delete_remboursements_fournisseurs' => 'Supprimer des remboursements fournisseurs',
            'export_remboursements_fournisseurs' => 'Exporter les remboursements fournisseurs',

            // === COMMISSIONS ===
            'view_commissions' => 'Voir les commissions',
            'create_commissions' => 'Créer des commissions',
            'edit_commissions' => 'Modifier des commissions',
            'delete_commissions' => 'Supprimer des commissions',
            'export_commissions' => 'Exporter les commissions',

            // === GESTION DES CHARGES ===
            'view_charges' => 'Voir les charges',
            'create_charges' => 'Créer des charges',
            'edit_charges' => 'Modifier des charges',
            'delete_charges' => 'Supprimer des charges',
            'export_charges' => 'Exporter les charges',

            // === MA CAISSE (liquidité manuelle) ===
            'view_caisse' => 'Voir la caisse',
            'create_caisse' => 'Ajouter des mouvements de caisse',
            'edit_caisse' => 'Modifier des mouvements de caisse',
            'delete_caisse' => 'Supprimer des mouvements de caisse',

            // === FINANCES (Comptable) ===
            'view_finances' => 'Voir les finances',
            'manage_finances' => 'Gérer les finances',
            'view_soldes' => 'Voir les soldes',
            'edit_soldes' => 'Modifier les soldes',
            'reconcile_accounts' => 'Réconcilier les comptes',

            // === RAPPORTS & STATISTIQUES ===
            'view_dashboard' => 'Voir le tableau de bord',
            'view_reports' => 'Voir les rapports',
            'view_statistics' => 'Voir les statistiques',
            'export_reports' => 'Exporter les rapports',

            // === JOURNAL D'ACTIVITÉ ===
            'view_activity_log' => 'Voir le journal d\'activité',
            'delete_activity_log' => 'Supprimer le journal d\'activité',

            // === SYSTÈME ===
            'view_system_settings' => 'Voir les paramètres système',
            'edit_system_settings' => 'Modifier les paramètres système',
            'manage_backups' => 'Gérer les sauvegardes',
            'view_notifications' => 'Voir les notifications',

            // === API ===
            'access_api' => 'Accéder à l\'API',
        ];

        // Créer toutes les permissions
        foreach ($permissions as $name => $description) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web']
            );
        }

        $this->command->info('✅ '.count($permissions).' permissions créées');

        // ============================================
        // CRÉATION DES RÔLES
        // ============================================

        // 0. SUPER_ADMIN - administrateur de la plateforme SaaS (hors de tout tenant).
        // Aucune permission métier : il gère les tenants dans /platform, jamais leurs données.
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web'])
            ->syncPermissions([]);

        // 1. MASTER - toutes les permissions du tenant, jamais les capacités globales/protégées.
        $master = Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
        $tenantPermissions = Permission::query()
            ->whereNotIn('name', User::FORBIDDEN_TENANT_PERMISSIONS)
            ->get();
        $master->syncPermissions($tenantPermissions);
        $this->command->info('✅ MASTER : '.$tenantPermissions->count().' permissions (administration complète du tenant)');

        // 2. ADMIN - mêmes autorisations que le master (chaque compte client a son admin). Ce qui l'en distingue n'est pas
        //    une permission mais une règle : il ne peut ni créer ni promouvoir un master (voir RoleAssignmentGuard).
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminPermissions = Permission::whereNotIn('name', User::FORBIDDEN_TENANT_PERMISSIONS)->pluck('name')->toArray();
        $admin->syncPermissions($adminPermissions);
        $this->command->info('✅ ADMIN : '.count($adminPermissions).' permissions (administration complète du tenant, sans pouvoir nommer un master)');

        // 3. COMPTABLE - Finances uniquement
        $comptable = Role::firstOrCreate(['name' => 'comptable', 'guard_name' => 'web']);
        $comptablePermissions = [
            // Vue générale
            'view_dashboard',
            'view_notifications',

            // Finances
            'view_finances',
            'manage_finances',
            'view_soldes',
            'reconcile_accounts',

            // Ma caisse (gestion complète)
            'view_caisse',
            'create_caisse',
            'edit_caisse',
            'delete_caisse',

            // Remboursements (gestion complète)
            'view_remboursements',
            'create_remboursements',
            'edit_remboursements',
            'export_remboursements',

            // Remboursements fournisseurs (gestion complète)
            'view_remboursements_fournisseurs',
            'create_remboursements_fournisseurs',
            'edit_remboursements_fournisseurs',
            'export_remboursements_fournisseurs',

            // Commissions (gestion complète)
            'view_commissions',
            'create_commissions',
            'edit_commissions',
            'export_commissions',

            // Charges (gestion complète, sauf suppression)
            'view_charges',
            'create_charges',
            'edit_charges',
            'export_charges',

            // Transactions UV (lecture + export)
            'view_uv_transactions',
            'export_uv_transactions',

            // Agents (lecture seule)
            'view_agents',
            'export_agents',

            // Fournisseurs (lecture)
            'view_fournisseurs',

            // Approvisionnements (lecture)
            'view_approvisionnements',

            // Rapports financiers
            'view_reports',
            'view_statistics',
            'export_reports',

            // Pas de journal d'activité (réservé à master/admin : il révèle les actions de tous les comptes)
        ];
        $comptable->syncPermissions($comptablePermissions);
        $this->command->info('✅ COMPTABLE : '.count($comptablePermissions).' permissions (finances)');

        // 4. COMMERCIAL - Agents & Transactions
        $commercial = Role::firstOrCreate(['name' => 'commercial', 'guard_name' => 'web']);
        $commercialPermissions = [
            // Vue générale
            'view_dashboard',
            'view_notifications',

            // Agents (gestion complète)
            'view_agents',
            'create_agents',
            'edit_agents',
            'export_agents',

            // Transactions UV (gestion complète)
            'view_uv_transactions',
            'create_uv_transactions',
            'edit_uv_transactions',
            'export_uv_transactions',

            // Fournisseurs (ajout et lecture)
            'view_fournisseurs',
            'create_fournisseurs',
            'edit_fournisseurs',

            // Approvisionnements (ajout et lecture)
            'view_approvisionnements',
            'create_approvisionnements',
            'edit_approvisionnements',

            // Remboursements (lecture seule)
            'view_remboursements',

            // Remboursements fournisseurs (lecture seule)
            'view_remboursements_fournisseurs',

            // Commissions (lecture seule)
            'view_commissions',

            // Soldes (lecture seule)
            'view_soldes',

            // Rapports
            'view_reports',
            'view_statistics',
            'export_reports',

            // Ni suppression d'agents (historique financier), ni journal d'activité (réservés à master/admin)
        ];
        $commercial->syncPermissions($commercialPermissions);
        $this->command->info('✅ COMMERCIAL : '.count($commercialPermissions).' permissions (agents/transactions)');

        // 5. LECTURE SEULE - consultation uniquement. Aucune permission de création, modification, suppression,
        // export, rapport, utilisateur, paramètre, journal d'activité ni API ; en plus, ReadOnlyGuard refuse toute
        // écriture côté serveur (même si une permission était ajoutée par erreur).
        $readOnly = Role::firstOrCreate(['name' => User::ROLE_READ_ONLY, 'guard_name' => 'web']);
        $readOnlyPermissions = [
            'view_dashboard',
            'view_notifications',
            'view_agents',
            'view_uv_transactions',
            'view_remboursements',
            'view_remboursements_fournisseurs',
            'view_approvisionnements',
            'view_fournisseurs',
            'view_commissions',
            'view_soldes',
            'view_statistics',
            'view_caisse',
            'view_charges',
            'view_finances',
        ];
        $readOnly->syncPermissions($readOnlyPermissions);
        $this->command->info('✅ LECTURE SEULE : '.count($readOnlyPermissions).' permissions (consultation)');

        // ============================================
        // ASSIGNER MASTER AU PREMIER UTILISATEUR D'UN TENANT
        // ============================================

        $firstUser = User::withoutGlobalScopes()
            ->whereNotNull('tenant_id')
            ->oldest('id')
            ->first();

        if ($firstUser && ! $firstUser->hasRole('master')) {
            $firstUser->assignRole('master');
            $this->command->info('✅ Rôle MASTER assigné à : '.$firstUser->email);
        }

        // ============================================
        // RÉSUMÉ
        // ============================================

        $this->command->newLine();
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('📊 RÉSUMÉ DES RÔLES ET PERMISSIONS');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $this->command->table(
            ['Rôle', 'Permissions', 'Accès'],
            [
                ['MASTER', $master->permissions->count(), '✅ ACCÈS TOTAL (toutes permissions)'],
                ['ADMIN', $admin->permissions->count(), '✅ Comme le master (ne nomme pas de master)'],
                ['COMPTABLE', $comptable->permissions->count(), '💰 Finances, remboursements, commissions'],
                ['COMMERCIAL', $commercial->permissions->count(), '👥 Agents, transactions UV, fournisseurs'],
                ['LECTURE SEULE', $readOnly->permissions->count(), '👁 Consultation uniquement (aucune écriture)'],
            ]
        );

        $this->command->newLine();
        $this->command->info('✅ Rôles et permissions créés avec succès !');
    }
}
