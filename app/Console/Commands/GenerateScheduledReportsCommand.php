<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Exports\UvTransactionsExport;
use App\Models\Agent;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class GenerateScheduledReportsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'reports:generate
                            {type=daily : Type de rapport (daily, weekly, monthly)}
                            {--email= : Email du destinataire (optionnel)}
                            {--format=both : Format d\'export (pdf, excel, both)}';

    protected $description = 'Générer et envoyer les rapports planifiés';

    public function handle(): int
    {
        $type = $this->argument('type');
        $email = $this->option('email');
        $format = $this->option('format');

        $this->info('');
        $this->info("📊 Génération du rapport {$type}...");
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('');

        // Définir la période selon le type
        [$startDate, $endDate, $periodLabel] = $this->getPeriod($type);

        $this->info("📅 Période : {$periodLabel}");
        $this->info("   Du {$startDate->format('d/m/Y')} au {$endDate->format('d/m/Y')}");
        $this->info('');

        // Collecter les données
        $data = $this->collectReportData($startDate, $endDate);

        // Générer les fichiers
        $files = [];

        if (in_array($format, ['pdf', 'both'])) {
            $this->line('📄 Génération PDF...');
            $files[] = $this->generatePdf($data, $type, $periodLabel);
            $this->info('   ✅ PDF généré');
        }

        if (in_array($format, ['excel', 'both'])) {
            $this->line('📊 Génération Excel...');
            $files[] = $this->generateExcel($data, $type, $startDate, $endDate);
            $this->info('   ✅ Excel généré');
        }

        // Envoyer par email si demandé
        if ($email || config('reports.auto_send_email', false)) {
            $recipient = $email ?: config('reports.default_recipient', 'admin@uvsystem.pro');
            $this->sendReportEmail($recipient, $files, $type, $periodLabel);
        }

        $this->info('');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('✅ Rapport généré avec succès !');
        $this->info('');
        $this->info('📁 Fichiers créés :');
        foreach ($files as $file) {
            $this->line("   • {$file}");
        }
        $this->info('');

        return self::SUCCESS;
    }

    protected function getPeriod(string $type): array
    {
        return match ($type) {
            'daily' => [
                now()->subDay()->startOfDay(),
                now()->subDay()->endOfDay(),
                'Rapport Journalier - '.now()->subDay()->format('d/m/Y'),
            ],
            'weekly' => [
                now()->subWeek()->startOfWeek(),
                now()->subWeek()->endOfWeek(),
                'Rapport Hebdomadaire - Semaine '.now()->subWeek()->weekOfYear,
            ],
            'monthly' => [
                now()->subMonth()->startOfMonth(),
                now()->subMonth()->endOfMonth(),
                'Rapport Mensuel - '.now()->subMonth()->translatedFormat('F Y'),
            ],
            default => [
                now()->startOfDay(),
                now()->endOfDay(),
                'Rapport - '.now()->format('d/m/Y'),
            ],
        };
    }

    protected function collectReportData($startDate, $endDate): array
    {
        $this->line('📈 Collecte des données...');

        // Transactions
        $transactions = UvTransaction::query()
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->with(['agent', 'fournisseur'])
            ->get();

        // Remboursements
        $remboursements = Remboursement::query()
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->with('agent')
            ->get();

        // Agents avec dette
        $agentsWithDebt = Agent::where('current_debt', '>', 0)
            ->orderBy('current_debt', 'desc')
            ->get();

        // Statistiques
        $stats = [
            'total_uv_distribues' => $transactions->where('transaction_type', 'distribution')->sum('amount'),
            'total_uv_retours' => $transactions->where('transaction_type', 'retour')->sum('amount'),
            'total_montant_transactions' => $transactions->sum('total_amount'),
            'total_remboursements' => $remboursements->sum('amount'),
            'nb_transactions' => $transactions->count(),
            'nb_remboursements' => $remboursements->count(),
            'nb_agents_actifs' => $transactions->pluck('agent_id')->unique()->count(),
            'dette_totale' => Agent::sum('current_debt'),
            'nb_agents_endettes' => $agentsWithDebt->count(),
        ];

        // Top 5 agents
        $topAgents = $transactions
            ->groupBy('agent_id')
            ->map(fn ($group) => [
                'agent' => $group->first()->agent,
                'total_uv' => $group->sum('amount'),
                'total_montant' => $group->sum('total_amount'),
            ])
            ->sortByDesc('total_montant')
            ->take(5)
            ->values();

        $this->info('   ✅ Données collectées');

        return [
            'transactions' => $transactions,
            'remboursements' => $remboursements,
            'agents_with_debt' => $agentsWithDebt,
            'stats' => $stats,
            'top_agents' => $topAgents,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    protected function generatePdf(array $data, string $type, string $periodLabel): string
    {
        $filename = "rapport_{$type}_".now()->format('Y-m-d_His').'.pdf';
        $path = "reports/{$filename}";

        $pdf = Pdf::loadView('exports.scheduled-report-pdf', [
            'data' => $data,
            'type' => $type,
            'periodLabel' => $periodLabel,
            'generatedAt' => now(),
            'company' => [
                'name' => config('app.company_name', 'MODELEC CI'),
                'address' => config('app.company_address', ''),
                'phone' => config('app.company_phone', ''),
                'email' => config('app.company_email', 'contact@uvsystem.pro'),
            ],
        ])->setPaper('a4', 'portrait');

        Storage::disk('local')->put($path, $pdf->output());

        return $path;
    }

    protected function generateExcel(array $data, string $type, $startDate, $endDate): string
    {
        $filename = "transactions_{$type}_".now()->format('Y-m-d_His').'.xlsx';
        $path = "reports/{$filename}";

        Excel::store(
            new UvTransactionsExport(
                null,
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d'),
                null
            ),
            $path,
            'local'
        );

        return $path;
    }

    protected function sendReportEmail(string $recipient, array $files, string $type, string $periodLabel): void
    {
        $this->line("📧 Envoi par email à {$recipient}...");

        try {
            $attachments = [];
            foreach ($files as $file) {
                $attachments[] = Storage::disk('local')->path($file);
            }

            Mail::send('emails.scheduled-report', [
                'type' => $type,
                'periodLabel' => $periodLabel,
                'generatedAt' => now(),
            ], function ($message) use ($recipient, $attachments, $periodLabel) {
                $message->to($recipient)
                    ->subject("📊 {$periodLabel} - MODELEC CI")
                    ->from(config('mail.from.address'), config('mail.from.name'));

                foreach ($attachments as $attachment) {
                    if (file_exists($attachment)) {
                        $message->attach($attachment);
                    }
                }
            });

            $this->info("   ✅ Email envoyé à {$recipient}");
        } catch (\Exception $e) {
            $this->error('   ❌ Erreur envoi email : '.$e->getMessage());
        }
    }
}
