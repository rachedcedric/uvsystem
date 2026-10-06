<?php

namespace App\Console\Commands;

use App\Tenancy\MigrationPreflight;
use Illuminate\Console\Command;

/**
 * Rapport préalable à la migration multi-tenant : tenants, données existantes, données sans tenant,
 * ce qui sera attribué au tenant n°1, tables héritées « clients* » et ce qui peut (ou non) être démontré,
 * sauvegarde. Lecture seule : aucune donnée n'est attribuée, supprimée, archivée ni déplacée.
 */
class TenancyPreflightCommand extends Command
{
    protected $signature = 'tenancy:preflight
                            {--backup= : Chemin d\'une sauvegarde manuelle (dump SQL / ZIP < 48 h) à vérifier et enregistrer}
                            {--accept-unattributed : Validation HUMAINE : les tables ambiguës resteront sans tenant, intactes (aucune attribution)}
                            {--note= : Justification de la validation (obligatoire avec --accept-unattributed)}';

    protected $description = 'Contrôle préalable (lecture seule) de la migration multi-tenant et validation humaine des cas ambigus';

    public function handle(): int
    {
        if ($path = $this->option('backup')) {
            $result = MigrationPreflight::recordBackup($path);
            $result['ok'] ? $this->info($result['message']) : $this->error($result['message']);
        }

        if ($this->option('accept-unattributed')) {
            if (strlen(trim((string) $this->option('note'))) < 10) {
                $this->error('--note="…" (10 caractères minimum) est obligatoire : la validation doit être motivée.');

                return self::FAILURE;
            }

            $this->warn('Vous validez que les tables ambiguës resteront SANS tenant, NON attribuées, intactes et inaccessibles à l\'application.');
            $this->warn('Cette validation n\'attribue AUCUNE donnée.');

            if (! $this->confirm('Confirmer cette validation humaine ?', false)) {
                $this->info('Validation annulée.');

                return self::FAILURE;
            }

            MigrationPreflight::acknowledge((string) $this->option('note'));
            $this->info('Validation enregistrée (liée à l\'état exact des données : toute modification l\'invalide).');
        }

        $verdict = MigrationPreflight::verdict();
        $report = $verdict['report'];

        $this->newLine();
        $this->line('<options=bold>RAPPORT PRÉALABLE À LA MIGRATION MULTI-TENANT</>');
        $this->line("Tenants trouvés : {$report['tenants']}");
        $this->newLine();

        $this->line('<options=bold>Tables cœur</> (la société d\'origine est la seule qui existait : rattachement au tenant n°1)');
        $this->table(['Table', 'Lignes existantes', 'Sans tenant', 'Seront attribuées au tenant n°1'],
            collect($report['core'])->map(fn ($t, $name) => [$name, $t['rows'], $t['without_tenant'], $t['without_tenant']])->values()->all());

        $this->newLine();
        $this->line('<options=bold>Tables héritées « clients* »</> (hors modèle — AUCUNE attribution automatique)');
        if ($report['legacy'] === []) {
            $this->line('Aucune table de ce type.');
        } else {
            $this->table(['Table', 'Lignes', 'Clés étrangères', 'Colonnes propriétaire', 'Démontrables (tenant n°1)', 'Ambiguës'],
                collect($report['legacy'])->map(fn ($t, $name) => [
                    $name, $t['rows'],
                    collect($t['foreign_keys'])->map(fn ($p, $c) => "{$c}→{$p}")->implode(', ') ?: '—',
                    collect($t['owner_columns_filled'])->map(fn ($n, $c) => "$c ($n)")->implode(', ') ?: 'aucune',
                    $t['demonstrable_tenant_1'], $t['ambiguous'],
                ])->values()->all());

            foreach ($report['legacy'] as $name => $t) {
                if ($t['ambiguous'] > 0) {
                    $this->error("{$name} : impossible à attribuer — ids ".implode(', ', $t['ambiguous_ids']).' ('.implode(' ; ', $t['ambiguous_reasons']).')');
                }
            }
        }

        $this->newLine();
        $this->line('Sauvegarde : '.($report['backup']['ok'] ? "<info>OK</info> — {$report['backup']['source']} : {$report['backup']['detail']}" : "<error>MANQUANTE</error> — {$report['backup']['detail']}"));
        $this->newLine();

        if ($verdict['allowed']) {
            $this->info('Migration autorisée : OUI');
            $this->line('Les tables héritées ambiguës (si validées) ne seront ni modifiées ni attribuées.');

            return self::SUCCESS;
        }

        $this->error('Migration autorisée : NON');
        foreach ($verdict['reasons'] as $reason) {
            $this->line("  • {$reason}");
        }

        $this->newLine();
        $this->error('MIGRATION BLOQUÉE : validation humaine requise (voir ci-dessus).');
        $this->line('  1. Faites une sauvegarde puis : php artisan tenancy:preflight --backup=/chemin/dump.sql');
        $this->line('  2. Relisez le rapport, puis : php artisan tenancy:preflight --accept-unattributed --note="motif"');

        return self::FAILURE;
    }
}
