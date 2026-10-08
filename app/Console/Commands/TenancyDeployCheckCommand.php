<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\MigrationPreflight;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/**
 * Vérification de déploiement, LECTURE SEULE : à lancer sur le serveur avant puis après la migration
 * multi-tenant. Ne modifie, ne supprime et ne déplace rien. Code de sortie 1 s'il existe au moins une ERREUR.
 */
class TenancyDeployCheckCommand extends Command
{
    protected $signature = 'tenancy:deploy-check';

    protected $description = 'Contrôle (lecture seule) de la configuration et des fichiers avant/après le déploiement multi-tenant';

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
    private array $rows = [];

    public function handle(): int
    {
        $this->checkEnvironment();
        $this->checkFiles();
        $this->checkMigrationState();
        $this->checkPlatform();

        $this->newLine();
        $this->table(['Statut', 'Contrôle', 'Constat', 'Action'], $this->rows);

        $errors = collect($this->rows)->where(0, 'ERREUR')->count();
        $warnings = collect($this->rows)->where(0, 'ATTENTION')->count();
        $this->newLine();
        $errors > 0
            ? $this->error("{$errors} erreur(s), {$warnings} point(s) d'attention : corrigez avant de migrer.")
            : $this->info("Aucune erreur ({$warnings} point(s) d'attention).");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function add(string $status, string $check, string $finding, string $action = ''): void
    {
        $this->rows[] = [$status, $check, $finding, $action];
    }

    // ------------------------------------------------------------------ environnement

    private function checkEnvironment(): void
    {
        $production = app()->environment('production');

        config('app.debug')
            ? $this->add($production ? 'ERREUR' : 'ATTENTION', 'APP_DEBUG', 'activé', 'APP_DEBUG=false (les erreurs détaillées exposent chemins et requêtes).')
            : $this->add('OK', 'APP_DEBUG', 'désactivé');

        str_starts_with((string) config('app.url'), 'https://')
            ? $this->add('OK', 'APP_URL', 'https')
            : $this->add($production ? 'ERREUR' : 'ATTENTION', 'APP_URL', (string) config('app.url'), 'Utiliser https (liens des e-mails, cookies sécurisés).');

        $default = config('filesystems.default');
        $default === 'public'
            ? $this->add('ATTENTION', 'FILESYSTEM_DISK', 'public : le disque par défaut est lisible par URL', 'Aucune écriture de l\'application n\'en dépend désormais (exports, rapports et justificatifs sont sur le disque privé) ; passez à « local ».')
            : $this->add('OK', 'FILESYSTEM_DISK', (string) $default);

        (int) config('sanctum.expiration') > 0
            ? $this->add('OK', 'Expiration des tokens API', config('sanctum.expiration').' min')
            : $this->add('ERREUR', 'Expiration des tokens API', 'illimitée', 'SANCTUM_TOKEN_EXPIRATION > 0.');

        config('security.authentication.2fa_enforce_from')
            ? $this->add('OK', '2FA master/admin', 'obligatoire à partir du '.config('security.authentication.2fa_enforce_from'))
            : $this->add('ATTENTION', '2FA master/admin', 'rappel seulement (SECURITY_2FA_ENFORCE_FROM vide)', 'Fixer une date quand tous l\'ont activée.');
    }

    // ------------------------------------------------------------------ fichiers

    private function checkFiles(): void
    {
        $public = Storage::disk('public');

        foreach (['reports' => 'rapports PDF', 'exports' => 'exports Excel', 'tenants' => 'dossier tenants'] as $dir => $label) {
            $count = $public->exists($dir) ? count($public->allFiles($dir)) : 0;
            $count > 0
                ? $this->add('ERREUR', "Fichiers publics : {$dir}/", "{$count} fichier(s) ({$label}) sur le disque public, lisibles par URL", "Les déplacer vers le disque privé ou les supprimer : storage/app/public/{$dir}/")
                : $this->add('OK', "Fichiers publics : {$dir}/", 'aucun');
        }

        $local = Storage::disk('local');
        $legacy = ($local->exists('exports') ? count($local->files('exports')) : 0) + ($local->exists('charges') ? count($local->allFiles('charges')) : 0);
        $legacy > 0
            ? $this->add('ATTENTION', 'Anciens fichiers à ranger', "{$legacy} fichier(s) hors dossier de tenant", 'php artisan tenancy:relocate-files (simulation), puis --apply.')
            : $this->add('OK', 'Anciens fichiers à ranger', 'aucun');

        $backups = $local->exists(config('app.name', 'uv-system')) ? count($local->files(config('app.name', 'uv-system'))) : 0;
        $this->add($backups > 0 ? 'OK' : 'ATTENTION', 'Sauvegardes', "{$backups} fichier(s) dans ".config('app.name', 'uv-system').'/', $backups > 0 ? '' : 'Faire une sauvegarde avant de migrer.');
    }

    // ------------------------------------------------------------------ migration

    private function checkMigrationState(): void
    {
        if (! Schema::hasTable('tenants')) {
            $verdict = MigrationPreflight::verdict();
            $verdict['allowed']
                ? $this->add('OK', 'Contrôle préalable', 'migration autorisée')
                : $this->add('ERREUR', 'Contrôle préalable', 'migration BLOQUÉE : '.implode(' ; ', $verdict['reasons']), 'php artisan tenancy:preflight (rapport et validation humaine).');
            $this->add('ATTENTION', 'Migration multi-tenant', 'pas encore appliquée', 'Après sauvegarde et préflight : php artisan migrate --force.');

            return;
        }

        $this->add('OK', 'Migration multi-tenant', Tenant::query()->count().' tenant(s)');

        $orphans = collect(MigrationPreflight::coreTables())
            ->filter(fn ($t) => Schema::hasTable($t) && Schema::hasColumn($t, 'tenant_id'))
            ->mapWithKeys(fn ($t) => [$t => (int) \DB::table($t)->whereNull('tenant_id')->count()])
            ->filter(fn ($n, $t) => $n > 0 && $t !== 'users');

        $orphans->isEmpty()
            ? $this->add('OK', 'Lignes sans tenant', 'aucune dans les tables métier')
            : $this->add('ERREUR', 'Lignes sans tenant', $orphans->map(fn ($n, $t) => "{$t}: {$n}")->implode(', '), 'Contacter le support : état incohérent.');

        if (! Schema::hasColumn('agents', 'email_valid')) {
            $this->add('ATTENTION', 'agents.email_valid', 'absente', 'php artisan migrate --force (les rebonds d\'agents ne sont pas enregistrés sans elle).');
        }
    }

    // ------------------------------------------------------------------ plateforme

    private function checkPlatform(): void
    {
        if (! Schema::hasTable('tenants')) {
            return;
        }

        Role::query()->where('name', User::ROLE_SUPER_ADMIN)->exists()
            ? $this->add('OK', 'Rôle super_admin', 'présent')
            : $this->add('ERREUR', 'Rôle super_admin', 'absent', 'php artisan db:seed --class=RolesAndPermissionsSeeder --force');

        $tenantRoles = User::TENANT_ROLES;
        $missingTenantRoles = collect($tenantRoles)
            ->diff(Role::query()->whereIn('name', $tenantRoles)->pluck('name'));

        $missingTenantRoles->isEmpty()
            ? $this->add('OK', 'Rôles tenant', 'tous présents')
            : $this->add('ERREUR', 'Rôles tenant', 'absents : '.$missingTenantRoles->implode(', '), 'php artisan db:seed --class=RolesAndPermissionsSeeder --force');

        $supers = TenantContext::withoutTenancy(fn () => User::query()->whereNull('tenant_id')->role(User::ROLE_SUPER_ADMIN)->count());
        $supers > 0
            ? $this->add('OK', 'Super admin', "{$supers} compte(s)")
            : $this->add('ATTENTION', 'Super admin', 'aucun', 'php artisan platform:super-admin votre@email.com');

        $suspended = Tenant::query()->where('status', Tenant::STATUS_SUSPENDED)->count();
        $this->add('OK', 'Tenants suspendus', (string) $suspended);
    }
}
