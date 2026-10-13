<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Models\Agent;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendPaymentReminders extends Command
{
    use RunsPerTenant;

    protected $signature = 'uv:send-reminders 
                            {--min-ratio=50 : Ratio minimum de dette pour envoyer un rappel (%)}
                            {--dry-run : Simule l\'envoi sans envoyer réellement}';

    protected $description = 'Envoie des rappels de paiement aux agents avec dette élevée';

    public function handle(NotificationService $notificationService): int
    {
        $minRatio = (float) $this->option('min-ratio');
        $dryRun = $this->option('dry-run');

        $this->info("📢 Envoi des rappels de paiement (ratio >= {$minRatio}%)...");

        if ($dryRun) {
            $this->warn('🔄 Mode simulation activé - aucun envoi réel');
        }

        if (! $dryRun) {
            $results = $notificationService->sendBulkPaymentReminders($minRatio);
        } else {
            // Mode simulation
            $results = [
                'total' => Agent::query()
                    ->where('status', 'active')
                    ->where('current_debt', '>', 0)
                    ->whereRaw('debt_limit > 0')
                    ->whereRaw('(current_debt / debt_limit * 100) >= ?', [$minRatio])
                    ->count(),
                'sent' => 0,
                'failed' => 0,
            ];
            $results['sent'] = $results['total'];
        }

        $this->newLine();
        $this->info('📊 Résultats:');
        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['📧 Total agents concernés', $results['total']],
                ['✅ Rappels envoyés', $results['sent']],
                ['❌ Échecs', $results['failed']],
            ]
        );

        $this->info('✅ Traitement terminé.');

        return Command::SUCCESS;
    }
}
