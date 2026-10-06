<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Services\RecoveryService;
use Illuminate\Console\Command;

class ProcessRecoveryCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'recovery:process
                            {--dry-run : Simulation sans actions réelles}
                            {--stats : Afficher uniquement les statistiques}';

    protected $description = 'Exécuter le processus de recouvrement automatisé';

    public function __construct(protected RecoveryService $recovery)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('');
        $this->info('💰 Processus de Recouvrement Automatisé');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('');

        // Mode statistiques uniquement
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('🔍 Mode simulation (--dry-run)');
            $this->info('');
        }

        // Afficher les seuils configurés
        $this->info('📊 Seuils de recouvrement :');
        $this->line('   • Warning  : 70% du plafond → Email d\'avertissement');
        $this->line('   • Alerte   : 80% du plafond → Email + SMS (rappels)');
        $this->line('   • Critique : 90% du plafond → Email + SMS urgents + Admins');
        $this->line('   • Blocage  : 100% du plafond → Compte bloqué automatiquement');
        $this->info('');

        // Afficher les stats actuelles
        $this->showStats(false);

        $this->info('');

        if ($dryRun) {
            $this->info('✅ Simulation terminée (aucune action effectuée)');

            return self::SUCCESS;
        }

        // Confirmation
        if (! $this->confirm('Lancer le processus de recouvrement ?', true)) {
            $this->warn('Processus annulé');

            return self::SUCCESS;
        }

        // Exécution
        $this->line('');
        $this->line('🔄 Traitement en cours...');

        $results = $this->recovery->processAllAgents();

        // Résultats
        $this->info('');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 Résultats du processus :');
        $this->info('');

        $this->table(
            ['Action', 'Nombre'],
            [
                ['Agents traités', $results['total']],
                ['Avertissements envoyés', $results['warnings_sent']],
                ['Alertes envoyées', $results['alerts_sent']],
                ['Alertes critiques', $results['critical_alerts']],
                ['Agents bloqués', $results['agents_blocked']],
                ['SMS envoyés', $results['sms_sent']],
            ]
        );

        if (! empty($results['errors'])) {
            $this->warn('');
            $this->warn('⚠️  Erreurs rencontrées :');
            foreach ($results['errors'] as $error) {
                $this->error("   Agent #{$error['agent_id']}: {$error['error']}");
            }
        }

        $this->info('');
        $this->info('✅ Processus de recouvrement terminé');
        $this->info('');

        return empty($results['errors']) ? self::SUCCESS : self::FAILURE;
    }

    protected function showStats(bool $exitAfter = true): int
    {
        $stats = $this->recovery->getStats();

        $this->info('📊 État actuel du recouvrement :');
        $this->info('');

        $this->table(
            ['Indicateur', 'Valeur'],
            [
                ['Agents endettés', $stats['total_agents_endettes']],
                ['Dette totale', number_format($stats['total_dette'], 0, ',', ' ').' FCFA'],
                ['Niveau Warning (70-79%)', $stats['warning_count']],
                ['Niveau Alerte (80-89%)', $stats['alert_count']],
                ['Niveau Critique (90-99%)', $stats['critical_count']],
                ['Comptes bloqués', $stats['blocked_count']],
            ]
        );

        // Alertes
        $this->info('');
        if ($stats['critical_count'] > 0) {
            $this->error("⚠️  {$stats['critical_count']} agent(s) en situation critique !");
        }
        if ($stats['blocked_count'] > 0) {
            $this->warn("🔒 {$stats['blocked_count']} compte(s) bloqué(s)");
        }

        if ($exitAfter) {
            $this->info('');
        }

        return self::SUCCESS;
    }
}
