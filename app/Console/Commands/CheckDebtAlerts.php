<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class CheckDebtAlerts extends Command
{
    use RunsPerTenant;

    protected $signature = 'uv:check-debt-alerts 
                            {--warning=70 : Seuil d\'alerte warning (%)}
                            {--critical=90 : Seuil d\'alerte critique (%)}';

    protected $description = 'Vérifie les seuils de dette des agents et envoie des alertes';

    public function handle(NotificationService $notificationService): int
    {
        $this->info('🔍 Vérification des seuils de dette...');

        $warning = (float) $this->option('warning');
        $critical = (float) $this->option('critical');

        $notificationService->setThresholds($warning, $critical);
        $results = $notificationService->checkAllAgentsDebt();

        $this->newLine();
        $this->info('📊 Résultats:');
        $this->table(
            ['Type', 'Nombre'],
            [
                ['🚨 Critiques (>='.$critical.'%)', $results['critical']],
                ['⚠️ Warnings (>='.$warning.'%)', $results['warning']],
                ['✅ OK', $results['ok']],
            ]
        );

        if ($results['critical'] > 0) {
            $this->error("⚠️ {$results['critical']} agent(s) en situation critique!");
        }

        $this->info('✅ Vérification terminée.');

        return Command::SUCCESS;
    }
}
