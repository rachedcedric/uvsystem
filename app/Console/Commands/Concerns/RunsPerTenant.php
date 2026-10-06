<?php

namespace App\Console\Commands\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Une commande planifiée (cron) n'a ni utilisateur ni tenant : sans ceci elle verrait les données
 * de tous les tenants et ne pourrait rien créer. Ce trait exécute la commande UNE FOIS PAR TENANT
 * ACTIF, dans le contexte de ce tenant (les e-mails/SMS/notifications ne partent donc qu'à ses
 * propres utilisateurs). Si un tenant est déjà posé (tests, runAs), la commande s'exécute une fois.
 */
trait RunsPerTenant
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (TenantContext::id() !== null) {
            return parent::execute($input, $output);
        }

        $status = self::SUCCESS;

        TenantContext::forEachTenant(function (Tenant $tenant) use ($input, $output, &$status) {
            $output->writeln("<comment>— Tenant #{$tenant->id} : {$tenant->name}</comment>");

            try {
                $status = max($status, (int) parent::execute($input, $output));
            } catch (\Throwable $e) {
                // Un tenant en erreur ne doit pas empêcher les autres d'être traités.
                report($e);
                $output->writeln("<error>Tenant #{$tenant->id} : {$e->getMessage()}</error>");
                $status = self::FAILURE;
            }
        });

        return $status;
    }
}
