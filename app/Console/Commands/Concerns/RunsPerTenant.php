<?php

namespace App\Console\Commands\Concerns;

use App\Models\Tenant;
use App\Services\Subscriptions\SubscriptionEntitlements;
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
            return $this->tenantHasRequiredFeature() ? parent::execute($input, $output) : self::SUCCESS;
        }

        $status = self::SUCCESS;

        TenantContext::forEachTenant(function (Tenant $tenant) use ($input, $output, &$status) {
            $output->writeln("<comment>— Tenant #{$tenant->id} : {$tenant->name}</comment>");

            if (! $this->tenantHasRequiredFeature()) {
                $output->writeln('<comment>  ignoré : fonctionnalité absente de son offre</comment>');

                return;
            }

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

    /** Fonctionnalité d'offre exigée pour traiter un tenant (null = aucune) ; surchargée par les commandes concernées. */
    protected function requiredFeature(): ?string
    {
        return null;
    }

    private function tenantHasRequiredFeature(): bool
    {
        $feature = $this->requiredFeature();

        return $feature === null || app(SubscriptionEntitlements::class)->hasFeature(null, $feature);
    }
}
