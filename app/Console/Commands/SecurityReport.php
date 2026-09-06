<?php

namespace App\Console\Commands;

use App\Services\SecurityService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SecurityReport extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'security:report
                            {--start= : Start date (YYYY-MM-DD)}
                            {--end= : End date (YYYY-MM-DD)}
                            {--output=table : Output format (table, json)}';

    /**
     * The console command description.
     */
    protected $description = 'Generate a comprehensive security report';

    protected SecurityService $securityService;

    public function __construct(SecurityService $securityService)
    {
        parent::__construct();
        $this->securityService = $securityService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔒 Génération du rapport de sécurité...');
        $this->newLine();

        // Dates par défaut: 30 derniers jours
        $start = $this->option('start')
            ? Carbon::parse($this->option('start'))
            : now()->subDays(30);

        $end = $this->option('end')
            ? Carbon::parse($this->option('end'))
            : now();

        // Générer le rapport
        $report = $this->securityService->generateSecurityReport($start, $end);

        // Afficher le rapport
        if ($this->option('output') === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));
        } else {
            $this->displayTableReport($report);
        }

        return self::SUCCESS;
    }

    /**
     * Display report as tables
     */
    protected function displayTableReport(array $report): void
    {
        // Période
        $this->components->twoColumnDetail(
            '<fg=blue>Période</fg=blue>',
            $report['period']['start'].' → '.$report['period']['end']
        );
        $this->newLine();

        // Statistiques générales
        $this->components->info('📊 STATISTIQUES DE SÉCURITÉ');
        $this->table(
            ['Métrique', 'Valeur', 'Statut'],
            [
                [
                    'Tentatives de connexion échouées',
                    $report['failed_logins'],
                    $this->getStatusIcon($report['failed_logins'], 10, true),
                ],
                [
                    'Activités suspectes',
                    $report['suspicious_activities'],
                    $this->getStatusIcon($report['suspicious_activities'], 5, true),
                ],
                [
                    'Transactions bloquées',
                    $report['blocked_transactions'],
                    $this->getStatusIcon($report['blocked_transactions'], 3, true),
                ],
            ]
        );
        $this->newLine();

        // Problèmes d'intégrité
        if (! empty($report['integrity_checks'])) {
            $this->components->error('⚠️  PROBLÈMES D\'INTÉGRITÉ DÉTECTÉS');

            $issuesData = [];
            foreach ($report['integrity_checks'] as $issue) {
                $issuesData[] = [
                    $issue['type'],
                    $issue['agent_name'] ?? $issue['transaction_id'] ?? 'N/A',
                    $issue['message'] ?? $this->formatIssueDetails($issue),
                ];
            }

            $this->table(['Type', 'Référence', 'Détails'], $issuesData);
            $this->newLine();
        } else {
            $this->components->info('✅ Aucun problème d\'intégrité détecté');
            $this->newLine();
        }

        // Recommandations
        $this->displayRecommendations($report);

        // Footer
        $this->components->twoColumnDetail(
            '<fg=gray>Généré le</fg=gray>',
            $report['generated_at']
        );
    }

    /**
     * Get status icon based on value
     */
    protected function getStatusIcon(int $value, int $threshold, bool $inverse = false): string
    {
        if ($inverse) {
            return $value > $threshold ? '<fg=red>⚠️  Critique</>' : '<fg=green>✅ Normal</>';
        }

        return $value >= $threshold ? '<fg=green>✅ OK</>' : '<fg=red>⚠️  Faible</>';
    }

    /**
     * Format issue details
     */
    protected function formatIssueDetails(array $issue): string
    {
        if ($issue['type'] === 'solde_mismatch') {
            $diff = $issue['difference'];

            return sprintf(
                'Écart: %s FCFA (Attendu: %s, Actuel: %s)',
                number_format($diff, 0, ',', ' '),
                number_format($issue['expected'], 0, ',', ' '),
                number_format($issue['actual'], 0, ',', ' ')
            );
        }

        return $issue['message'] ?? 'Détails non disponibles';
    }

    /**
     * Display security recommendations
     */
    protected function displayRecommendations(array $report): void
    {
        $recommendations = [];

        if ($report['failed_logins'] > 10) {
            $recommendations[] = 'Activer la limitation de tentatives de connexion plus stricte';
            $recommendations[] = 'Envisager l\'activation du 2FA pour tous les utilisateurs';
        }

        if ($report['suspicious_activities'] > 5) {
            $recommendations[] = 'Examiner les activités suspectes dans les logs';
            $recommendations[] = 'Renforcer les règles de détection de fraude';
        }

        if (! empty($report['integrity_checks'])) {
            $recommendations[] = 'URGENT: Corriger les problèmes d\'intégrité détectés';
            $recommendations[] = 'Vérifier les transactions affectées manuellement';
        }

        if (empty($recommendations)) {
            $this->components->info('✅ Aucune recommandation - La sécurité est optimale');
        } else {
            $this->components->warn('💡 RECOMMANDATIONS');
            foreach ($recommendations as $index => $recommendation) {
                $this->line(sprintf('  %d. %s', $index + 1, $recommendation));
            }
        }
        $this->newLine();
    }
}
