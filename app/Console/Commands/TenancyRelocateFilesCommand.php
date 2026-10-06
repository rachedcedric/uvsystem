<?php

namespace App\Console\Commands;

use App\Models\Charge;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Range dans tenants/{id}/ les fichiers créés avant le multi-tenant (charges/*, exports/*).
 * Idempotent. Sans --apply, simple simulation.
 */
class TenancyRelocateFilesCommand extends Command
{
    protected $signature = 'tenancy:relocate-files {tenant=1 : Identifiant du tenant propriétaire des anciens fichiers} {--apply}';

    protected $description = 'Déplace les anciens justificatifs et exports vers le dossier du tenant';

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant');
        $disk = Storage::disk('local');
        $apply = (bool) $this->option('apply');
        $moved = 0;

        TenantContext::runAs($tenantId, function () use ($disk, $apply, $tenantId, &$moved) {
            foreach (Charge::withTrashed()->whereNotNull('justificatif_path')->where('justificatif_path', 'not like', 'tenants/%')->get() as $charge) {
                $target = "tenants/{$tenantId}/".ltrim($charge->justificatif_path, '/');
                $this->line("{$charge->justificatif_path} → {$target}");
                if ($apply && $disk->exists($charge->justificatif_path)) {
                    $disk->move($charge->justificatif_path, $target);
                    $charge->forceFill(['justificatif_path' => $target])->saveQuietly();
                }
                $moved++;
            }

            foreach ($disk->files('exports') as $file) {
                $target = "tenants/{$tenantId}/{$file}";
                $this->line("{$file} → {$target}");
                if ($apply) {
                    $disk->move($file, $target);
                }
                $moved++;
            }
        });

        $this->info($apply ? "{$moved} fichier(s) déplacé(s)." : "{$moved} fichier(s) à déplacer. Relancez avec --apply.");

        return self::SUCCESS;
    }
}
