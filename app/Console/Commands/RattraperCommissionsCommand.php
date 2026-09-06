<?php

namespace App\Console\Commands;

use App\Services\CommissionService;
use Illuminate\Console\Command;

class RattraperCommissionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'commissions:rattraper';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rattraper automatiquement les commissions journalières manquantes ou incorrectes (filet de sécurité si commissions:calculer a été manqué à 18h02)';

    /**
     * Execute the console command.
     */
    public function handle(CommissionService $commissionService): int
    {
        $resultats = $commissionService->rattraperCommissionsManquantes();

        if (empty($resultats)) {
            $this->info('✅ Rien à rattraper — toutes les journées récentes sont à jour.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('🔧 %d journée(s) rattrapée(s) :', count($resultats)));
        $this->table(
            ['Date', 'Statut', 'Ancienne commission', 'Nouvelle commission'],
            collect($resultats)->map(fn ($r, $date) => [
                $date,
                $r['statut'],
                $r['ancienne_commission'] !== null ? number_format($r['ancienne_commission'], 0, ',', ' ').' FCFA' : '—',
                $r['nouvelle_commission'] !== null ? number_format($r['nouvelle_commission'], 0, ',', ' ').' FCFA' : '—',
            ])->values()->toArray()
        );

        $echecs = collect($resultats)->filter(fn ($r) => $r['statut'] === 'failed');
        if ($echecs->isNotEmpty()) {
            $this->error("⚠️  {$echecs->count()} échec(s) — voir storage/logs pour le détail.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
