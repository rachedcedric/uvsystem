<?php

namespace App\Console\Commands\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pour les commandes MANUELLES qui envoient des messages ou créent des données : sans tenant elles
 * toucheraient les agents de toutes les sociétés. L'option --tenant=ID est donc obligatoire et
 * la commande s'exécute dans le contexte de ce seul tenant (qui doit exister et être actif).
 * La commande doit déclarer {--tenant= : Identifiant du tenant (obligatoire)} dans sa signature.
 */
trait RunsForOneTenant
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenant = ctype_digit((string) $input->getOption('tenant')) ? Tenant::find((int) $input->getOption('tenant')) : null;

        if (! $tenant || ! $tenant->isActive()) {
            $output->writeln('<error>Option --tenant=ID obligatoire (tenant existant et actif) : cette commande ne s\'exécute jamais sur toutes les sociétés à la fois.</error>');

            return self::FAILURE;
        }

        $output->writeln("<comment>— Tenant #{$tenant->id} : {$tenant->name}</comment>");

        return (int) TenantContext::runAs($tenant->id, fn () => parent::execute($input, $output));
    }
}
