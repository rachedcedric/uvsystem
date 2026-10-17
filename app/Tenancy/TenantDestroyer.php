<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Suppression DÉFINITIVE d'une entreprise cliente et de toutes ses données (réservée au super admin de plateforme).
 *
 * La clé étrangère tenant_id → tenants est en RESTRICT : la base refuse de supprimer une entreprise qui a encore des
 * données. On supprime donc d'abord les données, table par table, enfants avant parents (l'ordre est calculé à partir des
 * clés étrangères réelles, donc une table ajoutée plus tard est prise en compte sans rien changer ici). Tout se passe dans
 * UNE transaction : au moindre échec (contrainte, panne), rien n'est supprimé. Les fichiers ne sont effacés qu'après la
 * validation de la transaction, pour qu'une suppression annulée ne perde aucun fichier.
 *
 * Les tables sans tenant_id mais liées aux utilisateurs de l'entreprise (rôles, notifications, jetons API, sessions) sont
 * nettoyées explicitement : aucune clé étrangère ne s'en charge.
 */
class TenantDestroyer
{
    /** Libellés des principales données affichées avant la confirmation. */
    private const SUMMARY = [
        'users' => 'utilisateur(s)',
        'agents' => 'agent(s)',
        'uv_transactions' => 'transaction(s) UV',
        'remboursements' => 'remboursement(s)',
        'caisse_mouvements' => 'mouvement(s) de caisse',
        'charges' => 'charge(s)',
    ];

    /** Phrase décrivant ce qui sera effacé, pour la fenêtre de confirmation. */
    public function describe(Tenant $tenant): string
    {
        $parts = [];

        foreach (self::SUMMARY as $table => $label) {
            if (Schema::hasTable($table)) {
                $parts[] = DB::table($table)->where('tenant_id', $tenant->getKey())->count().' '.$label;
            }
        }

        return implode(', ', $parts);
    }

    /** Vrai s'il s'agit de l'entreprise d'origine (celle qui porte les données antérieures au multi-entreprise). */
    public function isLegacyOwner(Tenant $tenant): bool
    {
        return Tenant::legacyOwnerId() === (int) $tenant->getKey();
    }

    /**
     * @return array<string, int> nombre de lignes supprimées, par table (les tables vides sont omises)
     *
     * @throws AuthorizationException si l'auteur n'est pas super admin de plateforme
     */
    public function destroy(Tenant $tenant, User $actor): array
    {
        if (! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Seul un super admin de plateforme peut supprimer une entreprise.');
        }

        $id = (int) $tenant->getKey();
        $name = (string) $tenant->name;

        $rows = TenantContext::withoutTenancy(fn () => DB::transaction(function () use ($id) {
            $this->purgeUserLinks(DB::table('users')->where('tenant_id', $id)->pluck('id')->all());

            $rows = [];

            foreach ($this->deletionOrder() as $table) {
                $rows[$table] = DB::table($table)->where('tenant_id', $id)->delete();
            }

            DB::table('tenants')->where('id', $id)->delete();

            return array_filter($rows);
        }));

        // Après la transaction seulement : un échec ci-dessus ne doit effacer aucun fichier.
        foreach (['local', 'public'] as $disk) {
            Storage::disk($disk)->deleteDirectory($tenant->storagePath());
        }

        $this->audit($id, $name, $actor, $rows);

        return $rows;
    }

    /**
     * Tables portant tenant_id, enfants avant parents : une table n'est supprimée que lorsqu'aucune table restante ne la
     * référence encore. Une auto-référence (ex. commissions.commission_globale_id) est ignorée : la base la gère (SET NULL).
     *
     * @return list<string>
     */
    private function deletionOrder(): array
    {
        $tables = array_values(array_filter(
            Schema::getTableListing(schema: null, schemaQualified: false),
            fn (string $table) => $table !== 'tenants' && Schema::hasColumn($table, 'tenant_id'),
        ));

        $references = [];

        foreach ($tables as $table) {
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $parent = $foreignKey['foreign_table'];

                if ($parent !== $table && in_array($parent, $tables, true)) {
                    $references[$table][$parent] = true;
                }
            }
        }

        $order = [];
        $remaining = $tables;

        while ($remaining !== []) {
            $referenced = [];

            foreach ($remaining as $table) {
                foreach (array_keys($references[$table] ?? []) as $parent) {
                    $referenced[$parent] = true;
                }
            }

            $ready = array_values(array_filter($remaining, fn (string $table) => ! isset($referenced[$table])));

            // Cycle (improbable) : on avance quand même ; si une contrainte bloque, la base refuse et tout est annulé.
            $ready = $ready === [] ? [$remaining[0]] : $ready;

            array_push($order, ...$ready);
            $remaining = array_values(array_diff($remaining, $ready));
        }

        return $order;
    }

    /** @param  list<int|string>  $userIds */
    private function purgeUserLinks(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $morph = (new User)->getMorphClass();

        foreach (array_chunk($userIds, 500) as $chunk) {
            foreach ([
                ['model_has_roles', 'model_type', 'model_id'],
                ['model_has_permissions', 'model_type', 'model_id'],
                ['notifications', 'notifiable_type', 'notifiable_id'],
                ['personal_access_tokens', 'tokenable_type', 'tokenable_id'],
            ] as [$table, $typeColumn, $idColumn]) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where($typeColumn, $morph)->whereIn($idColumn, $chunk)->delete();
                }
            }

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->whereIn('user_id', $chunk)->delete();
            }
        }
    }

    /** Trace de la suppression : journal applicatif (toujours) et journal d'audit de plateforme (sans tenant). */
    private function audit(int $id, string $name, User $actor, array $rows): void
    {
        Log::warning('Entreprise supprimée', ['tenant_id' => $id, 'name' => $name, 'by_user_id' => $actor->getKey(), 'rows' => $rows]);

        try {
            TenantContext::runAs(null, fn () => activity('plateforme')
                ->causedBy($actor)
                ->withProperties(['tenant_id' => $id, 'name' => $name, 'rows' => $rows])
                ->log("Entreprise supprimée : {$name} (#{$id})"));
        } catch (Throwable $e) {
            report($e); // la suppression est déjà faite et tracée dans le journal applicatif
        }
    }
}
