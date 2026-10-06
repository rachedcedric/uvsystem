<?php

namespace App\Console\Commands;

use App\Models\Charge;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Range dans tenants/{id}/ les fichiers créés avant le multi-tenant (charges/*, exports/*).
 *
 * Règle d'appartenance : seuls des fichiers ANTÉRIEURS au multi-tenant peuvent être déplacés, et
 * uniquement vers le tenant d'origine (celui créé par la migration), seule société qui existait.
 *  - justificatifs : le chemin doit être référencé par une charge de CE tenant ;
 *  - exports : le fichier doit être plus ancien que la création du tenant d'origine ;
 *  - un fichier plus récent, non référencé ou dont la destination existe déjà n'est JAMAIS déplacé.
 * Idempotent. Sans --apply : simulation.
 */
class TenancyRelocateFilesCommand extends Command
{
    protected $signature = 'tenancy:relocate-files {tenant? : Tenant d\'origine (déduit si omis)} {--apply}';

    protected $description = 'Déplace les anciens justificatifs et exports vers le dossier du tenant d\'origine, sans jamais changer de propriétaire';

    public function handle(): int
    {
        $owner = Tenant::legacyOwnerId();
        $requested = $this->argument('tenant');

        if ($owner === null) {
            $this->error('Aucun tenant : rien à ranger.');

            return self::FAILURE;
        }

        if ($requested !== null && (int) $requested !== $owner) {
            $this->error("Refusé : les fichiers antérieurs au multi-tenant appartiennent au tenant d'origine (#{$owner}), pas au tenant #{$requested}.");

            return self::FAILURE;
        }

        $origin = Tenant::findOrFail($owner);
        $apply = (bool) $this->option('apply');
        $disk = Storage::disk('local');
        $moved = $skipped = 0;

        TenantContext::runAs($owner, function () use ($disk, $apply, $origin, $owner, &$moved, &$skipped) {
            // 1. Justificatifs : seulement ceux référencés par une charge du tenant d'origine.
            foreach (Charge::withTrashed()->whereNotNull('justificatif_path')->where('justificatif_path', 'not like', 'tenants/%')->get() as $charge) {
                $target = "tenants/{$owner}/".ltrim($charge->justificatif_path, '/');
                [$moved, $skipped] = $this->relocate($disk, $charge->justificatif_path, $target, $apply, $moved, $skipped, function () use ($charge, $target) {
                    $charge->forceFill(['justificatif_path' => $target])->saveQuietly();
                });
            }

            // 2. Exports : propriétaire démontrable uniquement par la chronologie.
            $cutoff = $origin->created_at->getTimestamp();
            foreach ($disk->files('exports') as $file) {
                if ($disk->lastModified($file) > $cutoff) {
                    $this->warn("⏭  {$file} : postérieur à la création du multi-tenant, propriétaire non démontrable — non déplacé");
                    $skipped++;

                    continue;
                }
                [$moved, $skipped] = $this->relocate($disk, $file, "tenants/{$owner}/{$file}", $apply, $moved, $skipped);
            }
        });

        $this->info($apply ? "{$moved} fichier(s) déplacé(s), {$skipped} ignoré(s)." : "{$moved} fichier(s) à déplacer, {$skipped} ignoré(s). Relancez avec --apply.");

        return self::SUCCESS;
    }

    /** @return array{0:int,1:int} [déplacés, ignorés] */
    private function relocate($disk, string $from, string $to, bool $apply, int $moved, int $skipped, ?callable $afterMove = null): array
    {
        if ($disk->exists($to)) {
            $this->warn("⏭  {$from} : la destination existe déjà — non déplacé");

            return [$moved, $skipped + 1];
        }

        $this->line("{$from} → {$to}");

        if ($apply && $disk->exists($from)) {
            $disk->move($from, $to);
            $afterMove && $afterMove();
        }

        return [$moved + 1, $skipped];
    }
}
