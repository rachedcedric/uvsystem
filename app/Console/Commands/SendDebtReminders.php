<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendDebtReminders extends Command
{
    use RunsPerTenant;

    protected $signature = 'uv:send-debt-reminders';

    protected $description = 'Envoie un rappel (widget Notifications Récentes) pour chaque agent ayant une dette non soldée, un seul par cycle de 2h';

    public function handle(NotificationService $notificationService): int
    {
        $this->info('🔍 Vérification des dettes en cours...');

        $results = $notificationService->checkAndSendDebtReminders();

        $this->newLine();
        $this->table(
            ['Agents éligibles (dette > 0)', 'Rappels envoyés', 'Déjà notifiés ce cycle'],
            [[$results['eligible'], $results['sent'], $results['skipped_duplicate']]]
        );

        $this->info('✅ Vérification terminée.');

        return Command::SUCCESS;
    }
}
