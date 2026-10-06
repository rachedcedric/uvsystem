<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GuardsDemoCommands;
use App\Console\Commands\Concerns\RunsForOneTenant;
use App\Models\Agent;
use App\Models\Fournisseur;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearDemoData extends Command
{
    use GuardsDemoCommands;
    use RunsForOneTenant;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'demo:clear
                            {--tenant= : Identifiant du tenant (obligatoire)}
                            {--force : Ne pas demander de confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Supprime toutes les données de démonstration';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($refusal = $this->demoRefusal()) {
            $this->error('❌ Commande refusée : '.$refusal);

            return self::FAILURE;
        }

        // Statistiques avant suppression
        $stats = [
            'agents' => Agent::count(),
            'fournisseurs' => Fournisseur::count(),
            'transactions' => UvTransaction::count(),
            'remboursements' => Remboursement::count(),
        ];

        $this->info('📊 Données actuelles:');
        $this->table(
            ['Type', 'Nombre'],
            [
                ['Agents', $stats['agents']],
                ['Fournisseurs', $stats['fournisseurs']],
                ['Transactions', $stats['transactions']],
                ['Remboursements', $stats['remboursements']],
            ]
        );
        $this->newLine();

        // Confirmation
        if (! $this->option('force')) {
            if (! $this->confirm('⚠️  Voulez-vous vraiment supprimer TOUTES ces données?', false)) {
                $this->info('Opération annulée.');

                return self::SUCCESS;
            }
        }

        $this->warn('🗑️  Suppression des données en cours...');

        DB::transaction(function () {
            // Suppression dans l'ordre pour respecter les contraintes de clés étrangères
            $this->info('  - Suppression des remboursements...');
            Remboursement::query()->forceDelete();

            $this->info('  - Suppression des transactions UV...');
            UvTransaction::query()->forceDelete();

            $this->info('  - Suppression des soldes agents...');
            TenantContext::table('agent_soldes')->delete();

            $this->info('  - Suppression des agents...');
            Agent::query()->forceDelete();

            $this->info('  - Suppression des fournisseurs...');
            Fournisseur::query()->forceDelete();
        });

        $this->newLine();
        $this->info('✅ Toutes les données de démonstration ont été supprimées!');
        $this->info('💡 Utilisez "php artisan demo:setup" pour recréer des données de test.');

        return self::SUCCESS;
    }
}
