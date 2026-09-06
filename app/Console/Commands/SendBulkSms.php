<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\SmsService;
use Illuminate\Console\Command;

class SendBulkSms extends Command
{
    protected $signature = 'uv:send-sms 
                            {--type=all : Filter by agent type (all, standard, premium, vip)}
                            {--status=active : Filter by agent status (all, active, inactive)}
                            {--min-debt=0 : Minimum debt amount}
                            {--message= : Custom message to send}
                            {--template=reminder : Message template (reminder, alert, custom)}
                            {--dry-run : Preview without sending}';

    protected $description = 'Send bulk SMS to agents';

    public function handle(SmsService $smsService): int
    {
        // Check if SMS is enabled
        if (! $smsService->isEnabled() && ! $this->option('dry-run')) {
            $this->warn('SMS service is disabled. Use --dry-run to preview.');

            return Command::FAILURE;
        }

        // Build query
        $query = Agent::query();

        // Filter by type
        $type = $this->option('type');
        if ($type !== 'all') {
            $query->where('type', $type);
        }

        // Filter by status
        $status = $this->option('status');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        // Filter by minimum debt
        $minDebt = (float) $this->option('min-debt');
        if ($minDebt > 0) {
            $query->whereHas('solde', function ($q) use ($minDebt) {
                $q->where('solde_restant', '>=', $minDebt);
            });
        }

        // Only agents with phone numbers
        $query->whereNotNull('phone')->where('phone', '!=', '');

        $agents = $query->with('solde')->get();

        if ($agents->isEmpty()) {
            $this->info('Aucun agent correspondant aux criteres.');

            return Command::SUCCESS;
        }

        // Get message
        $message = $this->buildMessage($agents->first());
        $agentCount = $agents->count();

        // Preview mode
        if ($this->option('dry-run')) {
            $this->info('Mode Previsualisation (--dry-run)');
            $this->newLine();
            $this->info("Destinataires: {$agentCount} agents");
            $this->newLine();
            $this->info('Message:');
            $this->line($message);
            $this->newLine();

            $this->table(
                ['ID', 'Nom', 'Telephone', 'Dette'],
                $agents->take(10)->map(fn ($a) => [
                    $a->id,
                    $a->name,
                    $a->phone,
                    number_format($a->solde?->solde_restant ?? 0, 0, ',', ' ').' FCFA',
                ])
            );

            $remaining = $agentCount - 10;
            if ($remaining > 0) {
                $this->info("... et {$remaining} autres agents");
            }

            return Command::SUCCESS;
        }

        // Confirm before sending
        if (! $this->confirm("Envoyer des SMS a {$agentCount} agents?")) {
            $this->info('Operation annulee.');

            return Command::SUCCESS;
        }

        // Send SMS
        $this->output->progressStart($agentCount);

        $sent = 0;
        $failed = 0;

        foreach ($agents as $agent) {
            $personalMessage = $this->buildMessage($agent);

            if ($smsService->sendToAgent($agent, $personalMessage)) {
                $sent++;
            } else {
                $failed++;
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        $this->newLine();
        $this->info('Resultat:');
        $this->info("  Envoyes: {$sent}");
        $this->info("  Echoues: {$failed}");

        activity()
            ->withProperties([
                'total_agents' => $agentCount,
                'sent' => $sent,
                'failed' => $failed,
                'template' => $this->option('template'),
            ])
            ->log('SMS en masse envoyes');

        return Command::SUCCESS;
    }

    protected function buildMessage(Agent $agent): string
    {
        $template = $this->option('template');
        $customMessage = $this->option('message');

        if ($template === 'custom' && $customMessage) {
            // Replace placeholders in custom message
            return str_replace(
                ['{nom}', '{dette}', '{telephone}'],
                [
                    $agent->name,
                    number_format($agent->solde?->solde_restant ?? 0, 0, ',', ' '),
                    $agent->phone,
                ],
                $customMessage
            );
        }

        $debt = $agent->solde?->solde_restant ?? 0;

        return match ($template) {
            'alert' => "MODELEC CI: ALERTE\n".
                "Cher(e) {$agent->name},\n".
                'Votre dette s eleve a '.number_format($debt, 0, ',', ' ')." FCFA.\n".
                'Veuillez regulariser votre situation immediatement.',

            'reminder' => "MODELEC CI: Rappel\n".
                "Cher(e) {$agent->name},\n".
                'Votre solde est de '.number_format($debt, 0, ',', ' ')." FCFA.\n".
                'Merci de proceder au remboursement.',

            default => $customMessage ?? "MODELEC CI: Message\n".
                "Cher(e) {$agent->name},\n".
                'Merci de nous contacter pour plus d informations.',
        };
    }
}
