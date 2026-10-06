<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Services\Subscriptions\SubscriptionEntitlements;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDataExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Sauvegarde dédiée d'un tenant (fonctionnalité « sauvegardes_dediees ») : l'export complet de SES données,
 * rangé dans tenants/{id}/backups/ sur le disque privé, avec rotation. Exécutée une fois par tenant dont l'offre
 * inclut la fonctionnalité (les autres sont ignorés par RunsPerTenant). Les sauvegardes globales de la plateforme
 * (spatie/backup, réservées au super admin) restent un mécanisme distinct.
 */
class TenancyBackupDedicatedCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'tenancy:backup-dedicated';

    protected $description = 'Crée la sauvegarde dédiée des tenants dont l\'offre l\'inclut (rotation des plus anciennes)';

    protected function requiredFeature(): ?string
    {
        return 'sauvegardes_dediees';
    }

    public function handle(TenantDataExporter $exporter, SubscriptionEntitlements $entitlements): int
    {
        $zip = $exporter->build();

        try {
            $entitlements->assertCanStore((int) filesize($zip));

            $disk = Storage::disk('local');
            $path = TenantContext::storagePath('backups/backup-'.now()->format('Ymd-His').'.zip');
            $disk->put($path, file_get_contents($zip));
            $this->info("Sauvegarde créée : {$path}");

            $keep = (int) config('subscriptions.dedicated_backups_keep', 7);
            collect($disk->files(TenantContext::storagePath('backups')))->sort()->values()
                ->slice(0, max(0, count($disk->files(TenantContext::storagePath('backups'))) - $keep))
                ->each(fn (string $old) => $disk->delete($old));
        } finally {
            @unlink($zip);
        }

        return self::SUCCESS;
    }
}
