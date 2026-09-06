<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class SetupDemoData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'demo:setup
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
        if (! app()->environment('local', 'development', 'staging')) {
            $this->error('❌ Cette commande ne peut être exécutée qu\'en développement!');
            $this->error('   Environnement actuel: '.app()->environment());

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
        $agentsCreated = DB::table('agents')->count();
        $fournisseursCreated = DB::table('fournisseurs')->count();
        $transactions = DB::table('uv_transactions')->count();

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
