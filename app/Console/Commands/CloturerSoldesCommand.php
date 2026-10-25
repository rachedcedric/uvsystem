<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Services\ClotureSoldesService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Clôture quotidienne des soldes agents et fournisseurs (historique des dettes et crédits), pour chaque entreprise.
 * Idempotente : ne clôture que les journées terminées qui n'ont pas encore de clôture (rattrapage compris).
 */
class CloturerSoldesCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'soldes:cloturer
        {--depuis= : Première journée à reconstituer (AAAA-MM-JJ), jamais avant la première donnée de l\'entreprise}
        {--jusqua= : Dernière journée à clôturer (AAAA-MM-JJ), au plus tard hier}
        {--verifier : Contrôler aussi les clôtures récentes (écarts dus à des opérations antidatées ou annulées)}';

    protected $description = 'Enregistrer la photographie des soldes de fin de journée (23h59) et rattraper les journées manquées';

    public function handle(ClotureSoldesService $service): int
    {
        try {
            $depuis = $this->dateOption('depuis');
            $jusqua = $this->dateOption('jusqua');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $faites = $service->cloturerJourneesManquantes($jusqua, $depuis);
        $this->info($faites === []
            ? 'Clôtures à jour.'
            : count($faites).' journée(s) clôturée(s) : '.reset($faites).(count($faites) > 1 ? ' → '.end($faites) : '').'.');

        if ($this->option('verifier')) {
            $ecarts = $service->verifier();
            $ecarts > 0
                ? $this->warn($ecarts.' clôture(s) présentent un écart avec les mouvements actuels (soldes figés conservés).')
                : $this->info('Aucun écart sur les clôtures récentes.');
        }

        return self::SUCCESS;
    }

    private function dateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $value, config('app.timezone'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("Option --{$name} : date invalide (format AAAA-MM-JJ attendu).");
        }

        return $date;
    }
}
