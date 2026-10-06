<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Services\SecurityService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

class VerifyIntegrity extends Command
{
    use RunsPerTenant;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'security:verify-integrity
                            {--fix : Attempt to fix issues automatically}';

    /**
     * The console command description.
     */
    protected $description = 'Verify the integrity of financial transactions and data';

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
        $this->components->info('🔍 Vérification de l\'intégrité des données financières...');
        $this->newLine();

        // Vérifier l'intégrité
        $issues = $this->securityService->verifyTransactionIntegrity();

        if (empty($issues)) {
            $this->components->info('✅ Aucun problème d\'intégrité détecté');
            $this->line('   Toutes les transactions et soldes sont cohérents.');

            return self::SUCCESS;
        }

        // Afficher les problèmes
        $this->components->error(sprintf('⚠️  %d problème(s) d\'intégrité détecté(s)', count($issues)));
        $this->newLine();

        // Grouper par type
        $grouped = collect($issues)->groupBy('type');

        foreach ($grouped as $type => $typeIssues) {
            $this->displayIssuesByType($type, $typeIssues->toArray());
        }

        // Proposer la correction automatique
        if ($this->option('fix')) {
            $this->attemptAutoFix($issues);
        } else {
            $this->newLine();
            $this->components->warn('💡 Pour tenter une correction automatique, exécutez:');
            $this->line('   php artisan security:verify-integrity --fix');
        }

        return self::FAILURE;
    }

    /**
     * Display issues by type
     */
    protected function displayIssuesByType(string $type, array $issues): void
    {
        $this->components->twoColumnDetail(
            '<fg=yellow>Type de problème</fg=yellow>',
            $this->getTypeLabel($type)
        );

        $tableData = [];
        foreach ($issues as $issue) {
            $tableData[] = $this->formatIssueRow($type, $issue);
        }

        $headers = $this->getTableHeaders($type);
        $this->table($headers, $tableData);
        $this->newLine();
    }

    /**
     * Get type label
     */
    protected function getTypeLabel(string $type): string
    {
        return match ($type) {
            'invalid_signature' => 'Signature de transaction invalide',
            'solde_mismatch' => 'Écart de solde agent',
            'missing_transaction' => 'Transaction manquante',
            'duplicate_transaction' => 'Transaction en double',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    /**
     * Get table headers based on issue type
     */
    protected function getTableHeaders(string $type): array
    {
        return match ($type) {
            'invalid_signature' => ['ID Transaction', 'Agent', 'Montant', 'Date'],
            'solde_mismatch' => ['ID Agent', 'Nom', 'Attendu', 'Actuel', 'Écart'],
            default => ['ID', 'Description', 'Détails'],
        };
    }

    /**
     * Format issue row for table
     */
    protected function formatIssueRow(string $type, array $issue): array
    {
        if ($type === 'invalid_signature') {
            return [
                $issue['transaction_id'],
                $issue['agent_name'] ?? 'N/A',
                number_format($issue['amount'] ?? 0, 0, ',', ' ').' FCFA',
                $issue['date'] ?? 'N/A',
            ];
        }

        if ($type === 'solde_mismatch') {
            $diff = $issue['difference'];
            $color = $diff > 0 ? 'green' : 'red';

            return [
                $issue['agent_id'],
                $issue['agent_name'] ?? 'N/A',
                sprintf('<fg=blue>%s</>', number_format($issue['expected'], 0, ',', ' ')),
                sprintf('<fg=yellow>%s</>', number_format($issue['actual'], 0, ',', ' ')),
                sprintf('<fg=%s>%s%s</>',
                    $color,
                    $diff > 0 ? '+' : '',
                    number_format($diff, 0, ',', ' ')
                ),
            ];
        }

        return [
            $issue['id'] ?? 'N/A',
            $issue['message'] ?? 'N/A',
            json_encode($issue['details'] ?? [], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Attempt to fix issues automatically
     */
    protected function attemptAutoFix(array $issues): void
    {
        $this->newLine();
        $this->components->warn('🔧 Tentative de correction automatique...');
        $this->newLine();

        $fixed = 0;
        $failed = 0;

        foreach ($issues as $issue) {
            $this->line(sprintf('   Correction de: %s...', $issue['type']));

            try {
                if ($this->fixIssue($issue)) {
                    $this->components->info('   ✅ Corrigé');
                    $fixed++;
                } else {
                    $this->components->error('   ❌ Impossible de corriger automatiquement');
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->components->error('   ❌ Erreur: '.$e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->components->info(sprintf('Résultat: %d corrigé(s), %d échec(s)', $fixed, $failed));

        if ($failed > 0) {
            $this->newLine();
            $this->components->warn('⚠️  Certains problèmes nécessitent une intervention manuelle.');
        }
    }

    /**
     * Fix a specific issue
     */
    protected function fixIssue(array $issue): bool
    {
        // Pour le moment, on ne corrige que les écarts de solde
        if ($issue['type'] === 'solde_mismatch') {
            // Recalculer le solde à partir des transactions
            TenantContext::table('agent_soldes')
                ->where('agent_id', $issue['agent_id'])
                ->update([
                    'total_uv_distribues' => $issue['expected'],
                    'solde_restant' => $issue['expected'] - TenantContext::table('remboursements')
                        ->where('agent_id', $issue['agent_id'])
                        ->sum('amount'),
                    'updated_at' => now(),
                ]);

            return true;
        }

        // Les autres types nécessitent une intervention manuelle
        return false;
    }
}
