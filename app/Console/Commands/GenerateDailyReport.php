<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Models\Agent;
use App\Models\Remboursement;
use App\Models\User;
use App\Models\UvTransaction;
use App\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class GenerateDailyReport extends Command
{
    use RunsPerTenant;

    protected $signature = 'uv:daily-report
                            {--date= : Date du rapport (YYYY-MM-DD), défaut: hier}
                            {--email : Envoyer le rapport par email}
                            {--save : Sauvegarder le PDF dans storage}';

    protected $description = 'Génère un rapport quotidien des activités UV';

    public function handle(): int
    {
        $date = $this->option('date')
            ? \Carbon\Carbon::parse($this->option('date'))
            : now()->subDay();

        $this->info("📊 Génération du rapport pour le {$date->format('d/m/Y')}...");

        $data = $this->collectData($date);
        $this->displaySummary($data);

        if ($this->option('save') || $this->option('email')) {
            $pdfPath = $this->generatePdf($data, $date);
            $this->info("📄 PDF généré: {$pdfPath}");

            if ($this->option('email')) {
                $this->sendEmail($pdfPath, $date);
            }
        }

        $this->info('✅ Rapport terminé.');

        return Command::SUCCESS;
    }

    protected function collectData(\Carbon\Carbon $date): array
    {
        return [
            'date' => $date,
            'transactions' => [
                'count' => UvTransaction::whereDate('transaction_date', $date)->count(),
                'total_uv' => UvTransaction::whereDate('transaction_date', $date)
                    ->where('transaction_type', 'distribution')->sum('amount'),
                'total_amount' => UvTransaction::whereDate('transaction_date', $date)
                    ->where('transaction_type', 'distribution')->sum('amount'),
                'retours' => UvTransaction::whereDate('transaction_date', $date)
                    ->where('transaction_type', 'retour')->count(),
            ],
            'remboursements' => [
                'count' => Remboursement::whereDate('payment_date', $date)->count(),
                'total' => Remboursement::whereDate('payment_date', $date)->sum('amount'),
            ],
            'agents' => [
                'active' => Agent::where('status', 'active')->count(),
                'with_debt' => Agent::where('current_debt', '>', 0)->count(),
                'total_debt' => Agent::sum('current_debt'),
                'critical' => Agent::whereRaw('debt_limit > 0 AND (current_debt / debt_limit * 100) >= 90')->count(),
            ],
        ];
    }

    protected function displaySummary(array $data): void
    {
        $this->newLine();
        $this->info('📈 RÉSUMÉ DU JOUR');
        $this->table(['Métrique', 'Valeur'], [
            ['📦 Transactions', $data['transactions']['count']],
            ['📊 UV distribués', number_format($data['transactions']['total_uv'])],
            ['💰 CA du jour', number_format($data['transactions']['total_amount']).' FCFA'],
            ['↩️ Retours', $data['transactions']['retours']],
            ['💵 Remboursements', $data['remboursements']['count'].' ('.number_format($data['remboursements']['total']).' FCFA)'],
            ['👥 Agents actifs', $data['agents']['active']],
            ['⚠️ Agents avec dette', $data['agents']['with_debt']],
            ['🚨 Agents critiques', $data['agents']['critical']],
            ['💳 Dette totale', number_format($data['agents']['total_debt']).' FCFA'],
        ]);
    }

    protected function generatePdf(array $data, \Carbon\Carbon $date): string
    {
        $pdf = Pdf::loadView('exports.daily-report-pdf', $data)->setPaper('a4', 'portrait');
        $filename = "rapport_quotidien_{$date->format('Y-m-d')}.pdf";
        // Dossier du tenant : un tenant ne peut jamais écraser le rapport d'un autre.
        $path = TenantContext::storagePath("reports/{$filename}");
        // Disque PRIVÉ explicite : le disque par défaut peut être « public » (FILESYSTEM_DISK), donc lisible par URL.
        Storage::disk('local')->put($path, $pdf->output());

        return $path;
    }

    protected function sendEmail(string $pdfPath, \Carbon\Carbon $date): void
    {
        $users = User::all();
        foreach ($users as $user) {
            Mail::raw(
                "Bonjour,\n\nVeuillez trouver ci-joint le rapport quotidien du {$date->format('d/m/Y')}.\n\nCordialement,\nSystème UV",
                function ($message) use ($user, $pdfPath, $date) {
                    $message->to($user->email)
                        ->subject("📊 Rapport quotidien UV - {$date->format('d/m/Y')}")
                        ->attach(Storage::disk('local')->path($pdfPath));
                }
            );
        }
        $this->info("📧 Rapport envoyé à {$users->count()} utilisateur(s)");
    }
}
