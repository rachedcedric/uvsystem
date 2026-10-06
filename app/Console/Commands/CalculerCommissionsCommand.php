<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Models\PalierCommission;
use App\Services\CommissionService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CalculerCommissionsCommand extends Command
{
    use RunsPerTenant;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'commissions:calculer
                            {--date= : Date spécifique (format: Y-m-d)}
                            {--forcer : Conservé pour compatibilité (sans effet — le calcul est toujours vérifié et corrigé automatiquement)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculer la commission globale du jour (exécuté automatiquement à 18h02)';

    /**
     * Execute the console command.
     */
    public function handle(CommissionService $commissionService): int
    {
        $this->info('🏦 Calcul de la commission globale...');
        $this->newLine();

        // Afficher le barème depuis la base de données
        $paliers = PalierCommission::where('is_active', true)->orderBy('seuil_min')->get();

        if ($paliers->isEmpty()) {
            $this->error('❌ Aucun palier de commission configuré en base de données !');
            $this->info('💡 Exécutez: php artisan db:seed --class=PalierCommissionSeeder');

            return self::FAILURE;
        }

        $this->info('📊 Barème des commissions (depuis BDD) :');
        $this->table(
            ['Seuil Min (FCFA)', 'Seuil Max (FCFA)', 'Commission (FCFA)'],
            $paliers->map(fn ($p) => [
                number_format($p->seuil_min, 0, ',', ' '),
                number_format($p->seuil_max, 0, ',', ' '),
                number_format($p->commission, 0, ',', ' '),
            ])->toArray()
        );
        $this->newLine();

        // Déterminer la date
        $dateOption = $this->option('date');
        $date = $dateOption ? Carbon::parse($dateOption) : Carbon::today();
        $forcer = $this->option('forcer');

        [$periodeDebut, $periodeFin] = $commissionService->getPeriodeVentes($date);
        $this->info("📅 Date de commission : {$date->format('d/m/Y')}");
        $this->info('🕕 Période de ventes : '.$periodeDebut->format('d/m H:i:s').' → '.$periodeFin->format('d/m H:i:s'));
        $this->newLine();

        // Calculer les commissions (vérifie et corrige automatiquement,
        // idempotent — --forcer n'a plus d'effet sur le résultat, conservé
        // pour compatibilité).
        $this->info('⏳ Calcul en cours...');

        $stats = $commissionService->calculerCommissionsPourDate($date, $forcer, 'scheduled');

        // La commission était déjà correcte pour cette date (aucune
        // écriture n'a été nécessaire — le calcul est toujours vérifié et
        // corrigé automatiquement si besoin, que --forcer soit utilisé ou non).
        if ($stats['deja_calcule']) {
            $this->info('✅ Commission déjà à jour pour cette date (aucun changement nécessaire).');
            $this->info('   Volume: '.number_format($stats['volume_total'], 0, ',', ' ').' FCFA');
            $this->info('   Commission: '.number_format($stats['commission_globale'], 0, ',', ' ').' FCFA');

            return self::SUCCESS;
        }

        // Afficher les erreurs s'il y en a
        if (! empty($stats['erreurs'])) {
            $this->newLine();
            $this->error('❌ Erreurs :');
            foreach ($stats['erreurs'] as $erreur) {
                $this->line("  • {$erreur}");
            }

            return self::FAILURE;
        }

        // Afficher les résultats
        $this->newLine();
        $this->info('📈 RÉSULTAT :');
        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['Volume total du jour', number_format($stats['volume_total'], 0, ',', ' ').' FCFA'],
                ['Commission globale', number_format($stats['commission_globale'], 0, ',', ' ').' FCFA'],
                ['Nombre d\'agents ayant vendu', $stats['nombre_agents']],
                ['Nombre de transactions', $stats['nombre_transactions']],
            ]
        );

        // Palier appliqué
        if ($stats['palier_applique']) {
            $this->newLine();
            $this->info('📊 Palier appliqué :');
            $this->line('   '.number_format($stats['palier_applique']['seuil_min'], 0, ',', ' ').
                       ' - '.number_format($stats['palier_applique']['seuil_max'], 0, ',', ' ').
                       ' FCFA → '.number_format($stats['palier_applique']['commission'], 0, ',', ' ').' FCFA');
        }

        $this->newLine();
        $this->info('✅ Commission globale enregistrée avec succès !');

        return self::SUCCESS;
    }
}
