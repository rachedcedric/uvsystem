<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Models\Agent;
use App\Services\SmsService;
use Illuminate\Console\Command;

class SendSmsRemindersCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'sms:reminders 
                            {--min-ratio=70 : Ratio de dette minimum pour envoyer un rappel}
                            {--dry-run : Simulation sans envoi réel}
                            {--limit= : Limiter le nombre d\'envois}';

    protected $description = 'Envoyer des rappels SMS aux agents avec une dette importante';

    public function __construct(protected SmsService $sms)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('');
        $this->info('📱 Envoi des rappels SMS');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('');

        // Vérifier si le service est activé
        if (! $this->sms->isEnabled()) {
            $this->warn('⚠️  Service SMS désactivé');
            $this->info('Activez SMS_ENABLED=true dans .env');

            return self::FAILURE;
        }

        $minRatio = (float) $this->option('min-ratio');
        $dryRun = $this->option('dry-run');
        $limit = $this->option('limit');

        $this->info("📊 Critères : Ratio de dette >= {$minRatio}%");
        if ($dryRun) {
            $this->warn('🔍 Mode simulation (--dry-run)');
        }
        $this->info('');

        // Récupérer les agents concernés
        $query = Agent::query()
            ->where('status', 'active')
            ->where('current_debt', '>', 0)
            ->whereNotNull('phone');

        $agents = $query->get()->filter(function ($agent) use ($minRatio) {
            if ($agent->debt_limit <= 0) {
                return false;
            }
            $ratio = ($agent->current_debt / $agent->debt_limit) * 100;

            return $ratio >= $minRatio;
        });

        if ($limit) {
            $agents = $agents->take((int) $limit);
        }

        if ($agents->isEmpty()) {
            $this->info('✅ Aucun agent ne nécessite de rappel');

            return self::SUCCESS;
        }

        $this->info("📋 {$agents->count()} agent(s) à notifier");
        $this->info('');

        // Tableau récapitulatif
        $this->table(
            ['Agent', 'Téléphone', 'Dette', 'Plafond', 'Ratio'],
            $agents->map(fn ($a) => [
                $a->name,
                $a->phone,
                number_format($a->current_debt, 0, ',', ' ').' FCFA',
                number_format($a->debt_limit, 0, ',', ' ').' FCFA',
                round(($a->current_debt / $a->debt_limit) * 100, 1).'%',
            ])
        );

        $this->info('');

        // Confirmation avant envoi
        if (! $dryRun && ! $this->confirm('Confirmer l\'envoi des '.$agents->count().' SMS ?', false)) {
            $this->warn('Envoi annulé');

            return self::SUCCESS;
        }

        // Envoi des SMS
        $success = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($agents->count());
        $bar->start();

        foreach ($agents as $agent) {
            if ($dryRun) {
                $this->line('');
                $this->line("   [SIMULATION] SMS à {$agent->name} ({$agent->phone})");
                $success++;
            } else {
                if ($this->sms->sendPaymentReminder($agent, $agent->current_debt)) {
                    $success++;
                } else {
                    $failed++;
                }
            }
            $bar->advance();

            // Pause pour éviter le rate limiting
            if (! $dryRun) {
                usleep(500000); // 0.5 seconde entre chaque envoi
            }
        }

        $bar->finish();
        $this->info('');
        $this->info('');

        // Résumé
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 Résumé :');
        $this->info("   ✅ Envoyés : {$success}");
        if ($failed > 0) {
            $this->error("   ❌ Échoués : {$failed}");
        }
        $this->info('');

        // Log l'activité
        activity()
            ->withProperties([
                'type' => 'sms_reminders',
                'total' => $agents->count(),
                'success' => $success,
                'failed' => $failed,
                'min_ratio' => $minRatio,
                'dry_run' => $dryRun,
            ])
            ->log('Rappels SMS envoyés');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
