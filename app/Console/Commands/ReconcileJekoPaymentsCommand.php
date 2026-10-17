<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\Payments\JekoPaymentReconciler;
use Illuminate\Console\Command;

class ReconcileJekoPaymentsCommand extends Command
{
    protected $signature = 'subscriptions:reconcile-jeko {--limit=200 : Nombre maximal de paiements à vérifier}';

    protected $description = 'Réconcilie les paiements Jèko en attente lorsque le webhook de succès est absent';

    public function handle(JekoPaymentReconciler $reconciler): int
    {
        $report = $reconciler->run(max(1, (int) $this->option('limit')));

        $this->info("Vérifiés : {$report['checked']} — confirmés : {$report['confirmed']} — échoués : {$report['failed']} — expirés : {$report['expired']} — en attente : {$report['pending']} — erreurs : {$report['errors']}");

        return $report['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
