<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPerTenant;
use App\Models\Commission;
use App\Models\UvTransaction;
use App\Services\CommissionBusinessDateResolver;
use App\Services\CommissionService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class VerifierCommissionsHistoriqueCommand extends Command
{
    use RunsPerTenant;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'commissions:verifier
                            {--date= : Ne vérifier/recalculer qu\'une seule date (Y-m-d)}
                            {--debut= : Début de la période à vérifier (Y-m-d)}
                            {--fin= : Fin de la période à vérifier (Y-m-d)}
                            {--incomplete-only : Ne traiter que les journées manquantes ou marquées "à recalculer", sans revérifier les journées déjà calculées}
                            {--dry-run : Afficher ce qui serait fait sans rien écrire en base}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculer/vérifier les commissions journalières historiques à partir des ventes réelles (idempotent, ne supprime jamais de données)';

    public function handle(CommissionService $commissionService, CommissionBusinessDateResolver $dateResolver): int
    {
        [$debut, $fin] = $this->resoudrePeriode($dateResolver);

        if ($debut === null) {
            $this->info('Aucune vente en base — rien à vérifier.');

            return self::SUCCESS;
        }

        $dates = $this->listeDesJours($debut, $fin);

        if ($this->option('incomplete-only')) {
            $dates = $dates->filter(fn (Carbon $date) => $this->estIncomplete($date));
        }

        if ($dates->isEmpty()) {
            $this->info('✅ Aucune journée à traiter dans la période demandée.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '🔍 Vérification de %d journée(s) du %s au %s%s',
            $dates->count(),
            $debut->format('d/m/Y'),
            $fin->format('d/m/Y'),
            $this->option('dry-run') ? ' [DRY-RUN — aucune écriture]' : ''
        ));
        $this->newLine();

        $bar = $this->output->createProgressBar($dates->count());
        $bar->start();

        $correctes = 0;
        $recalculees = 0;
        $echecs = [];

        foreach ($dates as $date) {
            if ($this->option('dry-run')) {
                // En dry-run, on ne modifie rien : on compare simplement la
                // valeur stockée à la valeur qui serait recalculée.
                if ($this->seraitInchangee($date, $commissionService)) {
                    $correctes++;
                } else {
                    $recalculees++;
                }

                $bar->advance();

                continue;
            }

            $resultat = $commissionService->verifierEtCorrigerCommission($date, 'historical_backfill');

            match ($resultat['statut']) {
                'unchanged' => $correctes++,
                'created', 'updated' => $recalculees++,
                default => $echecs[] = $date->format('Y-m-d'),
            };

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['Journées vérifiées', $dates->count()],
                ['Correctes (aucun changement)', $correctes],
                ['Recalculées (créées ou corrigées)', $recalculees],
                ['Échecs', count($echecs)],
            ]
        );

        if (! empty($echecs)) {
            $this->error('Journées en échec : '.implode(', ', $echecs));

            return self::FAILURE;
        }

        $this->info(sprintf(
            '✅ %d journées vérifiées — %d correctes — %d recalculées.',
            $dates->count(),
            $correctes,
            $recalculees
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resoudrePeriode(CommissionBusinessDateResolver $dateResolver): array
    {
        if ($this->option('date')) {
            $date = Carbon::parse($this->option('date'))->startOfDay();

            return [$date, $date];
        }

        if ($this->option('debut') || $this->option('fin')) {
            $debut = Carbon::parse($this->option('debut') ?? $this->option('fin'))->startOfDay();
            $fin = Carbon::parse($this->option('fin') ?? $this->option('debut'))->startOfDay();

            return [$debut, $fin];
        }

        $premiereTransaction = UvTransaction::where('transaction_type', 'distribution')
            ->oldest('created_at')
            ->first();

        if (! $premiereTransaction) {
            return [null, null];
        }

        $debut = $dateResolver->resolve($premiereTransaction->created_at);
        // La journée en cours n'est pas encore clôturée : on s'arrête à la
        // dernière journée déjà close.
        $fin = $dateResolver->resolve(Carbon::now())->subDay();

        return [$debut, $fin->lessThan($debut) ? $debut : $fin];
    }

    private function listeDesJours(Carbon $debut, Carbon $fin): \Illuminate\Support\Collection
    {
        $jours = collect();
        $date = $debut->copy();

        while ($date->lessThanOrEqualTo($fin)) {
            $jours->push($date->copy());
            $date->addDay();
        }

        return $jours;
    }

    private function estIncomplete(Carbon $date): bool
    {
        $commission = Commission::where('is_global', true)
            ->whereDate('date_commission', $date)
            ->first();

        return $commission === null || $commission->statut === Commission::STATUT_RECALCULATION_REQUIRED;
    }

    private function seraitInchangee(Carbon $date, CommissionService $commissionService): bool
    {
        $commission = Commission::where('is_global', true)
            ->whereDate('date_commission', $date)
            ->first();

        if (! $commission || $commission->statut !== Commission::STATUT_CALCULATED) {
            return false;
        }

        [$periodeDebut, $periodeFin] = $commissionService->getPeriodeVentes($date);
        $volumeReel = (float) UvTransaction::where('transaction_type', 'distribution')
            ->whereBetween('created_at', [$periodeDebut, $periodeFin])
            ->sum('amount');
        $commissionReelle = $commissionService->calculerCommissionGlobale($volumeReel);

        return abs((float) $commission->volume_total_jour - $volumeReel) < 0.005
            && abs((float) $commission->commission_globale - $commissionReelle) < 0.005;
    }
}
