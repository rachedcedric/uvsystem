<?php

namespace App\Console\Commands;

use App\Services\CommissionScaleUpdater;
use App\Support\CommissionScale;
use Illuminate\Console\Command;

/**
 * Applique le barème de commissions aux masters existants, ou simule l'opération.
 *
 *   php artisan commissions:apply-scale --dry-run     (ne modifie RIEN : montre ce qui changerait, entreprise par entreprise)
 *   php artisan commissions:apply-scale               (applique)
 *   php artisan commissions:apply-scale --tenant=3    (une seule entreprise)
 *
 * Volontairement une COMMANDE et non une migration : elle change des taux de commission réels, donc elle se lance en connaissance
 * de cause, après avoir regardé la simulation. À lancer une fois après chaque changement du barème (App\Support\CommissionScale).
 */
class ApplyCommissionScaleCommand extends Command
{
    protected $signature = 'commissions:apply-scale {--dry-run : Simule seulement, sans rien modifier} {--tenant= : Numéro d\'une seule entreprise}';

    protected $description = 'Applique le barème de commissions (14 paliers) aux masters existants';

    public function handle(CommissionScaleUpdater $updater): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenant = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;

        $this->info(($dryRun ? 'SIMULATION — rien n\'est modifié. ' : '').count(CommissionScale::PALIERS).' paliers dans le barème.');

        $report = $updater->apply($tenant, $dryRun);

        if ($report === []) {
            $this->warn('Aucune entreprise.');

            return self::SUCCESS;
        }

        $this->table(
            ['Entreprise', 'Paliers créés', 'Paliers mis à jour', 'Anciens paliers désactivés'],
            collect($report)->map(fn (array $row, int $id) => ["#{$id} {$row['name']}", $row['created'], $row['updated'], $row['deactivated']])->all(),
        );

        $this->line($dryRun
            ? 'Pour appliquer : php artisan commissions:apply-scale'
            : 'Terminé. Les anciens paliers sont désactivés (pas supprimés) : un master peut les retrouver.');

        return self::SUCCESS;
    }
}
