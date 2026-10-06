<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GuardsDemoCommands;
use App\Console\Commands\Concerns\RunsForOneTenant;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SetupDemoData extends Command
{
    use GuardsDemoCommands;
    use RunsForOneTenant;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'demo:setup
                            {--tenant= : Identifiant du tenant (obligatoire)}
                            {--agents=5 : Nombre d\'agents à créer}
                            {--fournisseurs=5 : Nombre de fournisseurs à créer}
                            {--fresh : Réinitialiser la base de données avant}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crée des données de démonstration pour tester le système';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($refusal = $this->demoRefusal()) {
            $this->error('❌ Commande refusée : '.$refusal);

            return self::FAILURE;
        }

        $this->info('🚀 Configuration des données de démonstration...');
        $this->newLine();

        // Confirmation si fresh
        if ($this->option('fresh')) {
            if (! $this->confirm('⚠️  Cela va SUPPRIMER toutes les données existantes. Continuer?', false)) {
                $this->info('Opération annulée.');

                return self::SUCCESS;
            }

            $this->warn('🗑️  Réinitialisation de la base de données...');
            Artisan::call('migrate:fresh', [], $this->output);
            $this->newLine();

            // migrate:fresh ne recrée que le tenant d'origine : le tenant demandé doit encore exister.
            if (! Tenant::query()->whereKey(TenantContext::requireId())->exists()) {
                $this->error('❌ Le tenant demandé n\'existe plus après migrate:fresh (seul le tenant n°1 est recréé).');

                return self::FAILURE;
            }
        }

        // Configuration des variables d'environnement temporaires
        $agentsCount = $this->option('agents');
        $fournisseursCount = $this->option('fournisseurs');

        putenv("DEMO_AGENTS_COUNT={$agentsCount}");
        putenv("DEMO_FOURNISSEURS_COUNT={$fournisseursCount}");

        // Exécution des seeders
        $this->info("📊 Création de {$agentsCount} agents et {$fournisseursCount} fournisseurs...");
        $this->newLine();

        Artisan::call('db:seed', ['--class' => 'FournisseurSeeder'], $this->output);
        $this->newLine();

        Artisan::call('db:seed', ['--class' => 'AgentSeeder'], $this->output);
        $this->newLine();

        // Statistiques
        $agentsCreated = TenantContext::table('agents')->count();
        $fournisseursCreated = TenantContext::table('fournisseurs')->count();
        $transactions = TenantContext::table('uv_transactions')->count();

        $this->info('📈 Statistiques:');
        $this->table(
            ['Type', 'Nombre'],
            [
                ['Agents', $agentsCreated],
                ['Fournisseurs', $fournisseursCreated],
                ['Transactions', $transactions],
            ]
        );

        $this->newLine();
        $this->info('✅ Données de démonstration créées avec succès!');
        $this->info('🔗 Accédez à Filament: php artisan serve puis http://localhost:8000/admin');

        return self::SUCCESS;
    }
}
