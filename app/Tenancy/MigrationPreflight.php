<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Contrôle préalable de la migration multi-tenant. LECTURE SEULE (hors fichier de validation).
 *
 * Il ne décide JAMAIS d'une attribution : il détecte, compte, vérifie les relations, détermine ce qui est
 * démontrable et BLOQUE la migration tant qu'un humain n'a pas validé. Il ne supprime, n'archive ni ne
 * déplace aucune donnée.
 *
 *  - Tables cœur (agents, ventes, caisse…) : la société d'origine est la seule qui existait ; la migration
 *    les rattache au tenant n°1 (documenté, comptabilisé dans le rapport).
 *  - Tables héritées « clients* » (hors modèle) : propriété démontrée uniquement par des liens vers des
 *    données cœur ; toute ligne sans preuve, ou aux preuves contradictoires, est AMBIGUË → blocage.
 *    Même validée par un humain, la migration NE LES ATTRIBUE PAS : elles restent sans tenant, intactes.
 */
class MigrationPreflight
{
    public const ACK_PATH = 'tenancy/preflight-ack.json';

    /** Colonnes qui pourraient désigner un propriétaire. */
    private const OWNER_COLUMNS = ['tenant_id', 'user_id', 'created_by', 'company_id', 'owner_id'];

    /** Tables cœur tenantisées + comptes : la migration y pose tenant_id. */
    public static function coreTables(): array
    {
        $first = require database_path('migrations/2026_10_07_000001_create_tenants_and_add_tenant_id.php');

        return [...$first::TENANT_TABLES, 'users'];
    }

    /**
     * Noms des tables de la base COURANTE uniquement. Sur MySQL/MariaDB, Schema::getTables() sans schéma liste
     * les tables de toutes les bases visibles sur le serveur (production, préproduction, copies…) : on la borne
     * explicitement à la base de la connexion, sinon on contrôlerait des tables qui ne sont pas les nôtres.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private static function tableNames(): \Illuminate\Support\Collection
    {
        $connection = DB::connection();
        $schema = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) ? $connection->getDatabaseName() : null;

        return collect(Schema::getTables($schema))->pluck('name');
    }

    /** Tables héritées dont le nom commence par « client » et qui ne font pas partie du cœur. */
    public static function legacyTables(): array
    {
        return static::tableNames()
            ->filter(fn (string $name) => preg_match('/^clients?(_|$)/', $name) && ! in_array($name, static::coreTables(), true))
            ->sort()->values()->all();
    }

    /** Rapport complet (aucune écriture). */
    public static function inspect(): array
    {
        $core = [];
        foreach (static::coreTables() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = (int) DB::table($table)->count();
            $withoutTenant = Schema::hasColumn($table, 'tenant_id') ? (int) DB::table($table)->whereNull('tenant_id')->count() : $rows;
            $core[$table] = ['rows' => $rows, 'without_tenant' => $withoutTenant];
        }

        return [
            'tenants' => Schema::hasTable('tenants') ? (int) DB::table('tenants')->count() : 0,
            'core' => $core,
            'legacy' => static::inspectLegacy(),
            'backup' => static::backupStatus(),
        ];
    }

    /**
     * Propriétaire démontrable de chaque ligne des tables héritées. Deux sortes de preuves, sans propagation
     * circulaire (un parent partagé ne contamine pas ses enfants) :
     *  - MONTANTE : la colonne propriétaire de la ligne, ses liens vers des données cœur, et la preuve montante
     *    de ses parents hérités (un solde client hérite de son client) ;
     *  - DIRECTE DES RÉFÉRENCES : les liens cœur / colonne propriétaire de chaque ligne qui la référence (une
     *    catégorie est démontrée par les clients qui l'utilisent ; un client aussi par une vente qui le cite).
     * Une ligne est démontrable si l'ensemble de ses preuves désigne UN SEUL propriétaire, le tenant d'origine.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function inspectLegacy(): array
    {
        $legacy = static::legacyTables();
        $core = static::coreTables();
        $edges = [];     // [enfant, colonne, parent]
        foreach ($legacy as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (count($fk['columns']) === 1) {
                    $edges[] = [$table, $fk['columns'][0], $fk['foreign_table']];
                }
            }
        }

        $rows = [];      // [table][id] => objet de ligne
        foreach ($legacy as $table) {
            foreach (DB::table($table)->get() as $row) {
                $rows[$table][$row->id] = $row;
            }
        }

        /** Preuves directes d'une ligne : colonne propriétaire + liens vers des tables cœur. */
        $direct = function (string $table, object $row) use ($edges, $core): array {
            $tokens = [];
            if (Schema::hasColumn($table, 'tenant_id') && $row->tenant_id !== null) {
                $tokens[] = 'T'.$row->tenant_id;
            }
            foreach ($edges as [$child, $column, $parent]) {
                if ($child === $table && in_array($parent, $core, true) && $row->{$column} !== null && ($t = static::coreOwnerToken($parent, $row->{$column}))) {
                    $tokens[] = $t;
                }
            }

            return $tokens;
        };

        /** Preuve montante (mémoïsée, protégée contre les cycles). */
        $memo = [];
        $up = function (string $table, mixed $id) use (&$up, &$memo, $rows, $edges, $legacy, $direct): array {
            if (isset($memo[$table][$id])) {
                return $memo[$table][$id];
            }
            $memo[$table][$id] = []; // garde anti-cycle
            $row = $rows[$table][$id] ?? null;
            $tokens = $row ? $direct($table, $row) : [];
            foreach ($edges as [$child, $column, $parent]) {
                if ($row && $child === $table && in_array($parent, $legacy, true) && $row->{$column} !== null) {
                    $tokens = array_merge($tokens, $up($parent, $row->{$column}));
                }
            }

            return $memo[$table][$id] = $tokens;
        };

        // Preuves directes apportées aux parents par les lignes qui les référencent (héritées ou cœur).
        $referrers = []; // [table][id] => jetons
        foreach ($edges as [$child, $column, $parent]) {
            if (! in_array($parent, $legacy, true)) {
                continue;
            }
            foreach ($rows[$child] ?? [] as $row) {
                if ($row->{$column} !== null) {
                    $referrers[$parent][$row->{$column}] = array_merge($referrers[$parent][$row->{$column}] ?? [], $direct($child, $row));
                }
            }
        }
        foreach ($core as $coreTable) {
            if (! Schema::hasTable($coreTable)) {
                continue;
            }
            foreach (Schema::getForeignKeys($coreTable) as $fk) {
                if (count($fk['columns']) !== 1 || ! in_array($fk['foreign_table'], $legacy, true)) {
                    continue;
                }
                foreach (DB::table($coreTable)->whereNotNull($fk['columns'][0])->get(['id', $fk['columns'][0]]) as $row) {
                    if ($token = static::coreOwnerToken($coreTable, $row->id)) {
                        $referrers[$fk['foreign_table']][$row->{$fk['columns'][0]}][] = $token;
                    }
                }
            }
        }

        $report = [];
        foreach ($legacy as $table) {
            $columns = Schema::getColumnListing($table);
            $demonstrable = 0;
            $ambiguous = [];

            foreach (array_keys($rows[$table] ?? []) as $id) {
                $tokens = array_values(array_unique(array_merge($up($table, $id), $referrers[$table][$id] ?? [])));
                if (count($tokens) === 1 && static::isOriginOwner($tokens[0])) {
                    $demonstrable++;
                } else {
                    $ambiguous[$id] = count($tokens) === 0 ? 'aucune preuve de propriétaire' : 'preuves contradictoires ou non attribuables';
                }
            }

            $report[$table] = [
                'rows' => count($rows[$table] ?? []),
                'foreign_keys' => collect($edges)->where(0, $table)->mapWithKeys(fn ($e) => [$e[1] => $e[2]])->all(),
                'owner_columns' => array_values(array_intersect($columns, self::OWNER_COLUMNS)),
                'owner_columns_filled' => collect(array_intersect($columns, self::OWNER_COLUMNS))->mapWithKeys(fn ($c) => [$c => (int) DB::table($table)->whereNotNull($c)->count()])->all(),
                'demonstrable_tenant_1' => $demonstrable,
                'ambiguous' => count($ambiguous),
                'ambiguous_ids' => array_slice(array_keys($ambiguous), 0, 25),
                'ambiguous_reasons' => array_values(array_unique($ambiguous)),
            ];
        }

        return $report;
    }

    /**
     * Jeton de propriétaire d'une ligne cœur : « legacy » avant migration (la seule société existante,
     * donc le futur tenant n°1) ou « T<id> » une fois la colonne présente. null si la ligne n'existe pas.
     */
    private static function coreOwnerToken(string $table, mixed $id): ?string
    {
        if (! Schema::hasTable($table) || ! DB::table($table)->where('id', $id)->exists()) {
            return null;
        }

        if (! Schema::hasColumn($table, 'tenant_id')) {
            return 'legacy';
        }

        $tenant = DB::table($table)->where('id', $id)->value('tenant_id');

        return $tenant === null ? null : 'T'.$tenant;
    }

    /** Le jeton désigne-t-il le tenant d'origine (avant migration, ou tenant n°1 / premier tenant) ? */
    private static function isOriginOwner(string $token): bool
    {
        if ($token === 'legacy') {
            return true;
        }

        $origin = Schema::hasTable('tenants') ? DB::table('tenants')->min('id') : null;

        return $origin !== null && $token === 'T'.$origin;
    }

    // ------------------------------------------------------------------ sauvegarde

    /** @return array{ok: bool, source: ?string, detail: string} */
    public static function backupStatus(): array
    {
        // 1. Sauvegarde spatie récente (< 24 h, archive valide)
        $disk = Storage::disk('local');
        $dir = config('app.name', 'uv-system');
        $best = collect($disk->exists($dir) ? $disk->files($dir) : [])
            ->filter(fn ($f) => str_ends_with($f, '.zip') && $disk->size($f) > 0 && $disk->lastModified($f) >= now()->subDay()->getTimestamp())
            ->sortByDesc(fn ($f) => $disk->lastModified($f))->first();

        if ($best && static::validZip($disk->path($best))) {
            return ['ok' => true, 'source' => 'spatie', 'detail' => basename($best).' ('.date('d/m/Y H:i', $disk->lastModified($best)).')'];
        }

        // 2. Sauvegarde manuelle déclarée puis vérifiée (fichier toujours présent, même taille, < 48 h)
        $ack = static::readAck();
        if (isset($ack['backup']['path']) && is_file($ack['backup']['path'])
            && filesize($ack['backup']['path']) === ($ack['backup']['size'] ?? -1)
            && filemtime($ack['backup']['path']) >= now()->subHours(48)->getTimestamp()) {
            return ['ok' => true, 'source' => 'manuelle', 'detail' => basename($ack['backup']['path']).' ('.number_format($ack['backup']['size'] / 1024, 0, ',', ' ').' Ko)'];
        }

        return ['ok' => false, 'source' => null, 'detail' => 'aucune sauvegarde de moins de 24 h dans '.$dir.'/ et aucune sauvegarde manuelle vérifiée'];
    }

    /** Vérifie un fichier de sauvegarde fourni par l'opérateur et l'enregistre. @return array{ok: bool, message: string} */
    public static function recordBackup(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return ['ok' => false, 'message' => 'Fichier introuvable ou illisible.'];
        }
        if (filesize($path) < 1024) {
            return ['ok' => false, 'message' => 'Fichier trop petit pour être une sauvegarde de base (< 1 Ko).'];
        }
        if (filemtime($path) < now()->subHours(48)->getTimestamp()) {
            return ['ok' => false, 'message' => 'Sauvegarde trop ancienne (> 48 h) : refaites-la juste avant la migration.'];
        }
        if (str_ends_with($path, '.zip') && ! static::validZip($path)) {
            return ['ok' => false, 'message' => 'Archive ZIP invalide ou corrompue.'];
        }

        $ack = static::readAck();
        $ack['backup'] = ['path' => $path, 'size' => filesize($path), 'recorded_at' => now()->toIso8601String()];
        static::writeAck($ack);

        return ['ok' => true, 'message' => 'Sauvegarde vérifiée et enregistrée.'];
    }

    private static function validZip(string $path): bool
    {
        $zip = new ZipArchive;
        $ok = $zip->open($path, ZipArchive::CHECKCONS) === true && $zip->numFiles > 0;
        $zip->close();

        return $ok;
    }

    // ------------------------------------------------------------------ validation humaine

    /** Empreinte de l'état ambigu : toute modification des données invalide la validation donnée. */
    public static function fingerprint(array $legacy): string
    {
        return sha1(json_encode(collect($legacy)->map(fn ($t) => [$t['rows'], $t['ambiguous'], $t['ambiguous_ids']])->all()));
    }

    /**
     * Enregistre la validation HUMAINE : l'opérateur a relu le rapport et accepte que les tables ambiguës
     * restent SANS tenant, non attribuées, intactes. Ce n'est PAS une attribution.
     */
    public static function acknowledge(string $note): array
    {
        $report = static::inspect();
        $ack = static::readAck();
        $ack['unattributed'] = [
            'tables' => collect($report['legacy'])->filter(fn ($t) => $t['ambiguous'] > 0)->keys()->all(),
            'fingerprint' => static::fingerprint($report['legacy']),
            'note' => $note,
            'acknowledged_at' => now()->toIso8601String(),
        ];
        static::writeAck($ack);

        return $ack['unattributed'];
    }

    private static function acknowledged(array $legacy): bool
    {
        $ack = static::readAck()['unattributed'] ?? null;

        return $ack !== null && ($ack['fingerprint'] ?? null) === static::fingerprint($legacy);
    }

    // ------------------------------------------------------------------ verdict

    /** @return array{allowed: bool, reasons: list<string>, report: array<string, mixed>} */
    public static function verdict(): array
    {
        $report = static::inspect();
        $reasons = [];

        $ambiguous = collect($report['legacy'])->filter(fn ($t) => $t['ambiguous'] > 0);
        if ($ambiguous->isNotEmpty() && ! static::acknowledged($report['legacy'])) {
            $reasons[] = 'AMBIGU : '.$ambiguous->map(fn ($t, $name) => "{$name} ({$t['ambiguous']} ligne(s) sur {$t['rows']})")->implode(', ');
        }

        $coreRows = collect($report['core'])->sum('rows');
        if ($coreRows > 0 && ! $report['backup']['ok'] && ! app()->environment('testing')) {
            $reasons[] = 'SAUVEGARDE : '.$report['backup']['detail'];
        }

        return ['allowed' => $reasons === [], 'reasons' => $reasons, 'report' => $report];
    }

    /** Appelée en tout premier par la migration : rien n'est modifié si elle lève. */
    public static function assertCanMigrate(): void
    {
        // Chemin rapide : base vide (installation neuve, tests) et aucune table héritée => rien à contrôler.
        // Évite des dizaines de requêtes sur information_schema à chaque migration.
        if (static::isEmptyDatabase()) {
            return;
        }

        $verdict = static::verdict();

        if ($verdict['allowed']) {
            return;
        }

        $ambiguous = collect($verdict['reasons'])->contains(fn ($r) => str_starts_with($r, 'AMBIGU'));

        throw new MigrationBlocked(
            "MIGRATION BLOQUÉE :\n"
            .($ambiguous
                ? "Impossible de déterminer avec certitude le tenant propriétaire\ndes données existantes.\nValidation humaine requise.\n"
                : "Aucune sauvegarde vérifiable : migration refusée.\n")
            .'- '.implode("\n- ", $verdict['reasons'])
            ."\nRapport détaillé et validation : php artisan tenancy:preflight"
        );
    }

    /** Aucune ligne dans les tables cœur et aucune table héritée « clients* » : rien à attribuer ni à valider. */
    private static function isEmptyDatabase(): bool
    {
        $existing = static::tableNames();
        $core = static::coreTables();

        if ($existing->contains(fn ($name) => preg_match('/^clients?(_|$)/', $name) && ! in_array($name, $core, true))) {
            return false;
        }

        foreach ($core as $table) {
            if ($existing->contains($table) && DB::table($table)->exists()) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ fichier de validation

    private static function readAck(): array
    {
        $disk = Storage::disk('local');

        return $disk->exists(self::ACK_PATH) ? (json_decode($disk->get(self::ACK_PATH), true) ?: []) : [];
    }

    private static function writeAck(array $ack): void
    {
        Storage::disk('local')->put(self::ACK_PATH, json_encode($ack, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
