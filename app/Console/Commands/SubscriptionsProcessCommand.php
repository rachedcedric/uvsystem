<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\SubscriptionLifecycleProcessor;
use Illuminate\Console\Command;

class SubscriptionsProcessCommand extends Command
{
    protected $signature = 'subscriptions:process';

    protected $description = 'Expire les essais et abonnements échus et envoie les avertissements J-7 / J-3 / J-1 (idempotent)';

    public function handle(SubscriptionLifecycleProcessor $processor): int
    {
        $report = $processor->run();

        $this->info("Expirés : {$report['expired']} — avertissements envoyés : {$report['warned']} — erreurs : {$report['errors']}");

        // Code de sortie non nul si une erreur a été journalisée : le planificateur la signale.
        return $report['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
