<?php

namespace App\Services\Reports;

use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\Remboursement;
use App\Models\RemboursementFournisseur;
use App\Models\UvTransaction;
use App\Services\FournisseurDebtService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * « Historique des dettes et crédits » : pour une période, la situation de chaque agent et de chaque fournisseur à
 * crédit, reconstituée depuis les mouvements réellement enregistrés (jamais un solde mis en cache) :
 *
 *   ouverture  = solde à la veille du début de période (23:59:59) ;
 *   nouvelles  = dettes nées pendant la période (ventes à l'agent / approvisionnements à crédit du fournisseur) ;
 *   payé       = règlements de la période (remboursements + retours d'UV de l'agent / remboursements non annulés
 *                au fournisseur) ;
 *   solde      = ouverture + nouvelles − payé = solde à la fin de la période (consultation du solde à une date passée).
 *
 * Agent : solde > 0 = l'agent doit au Master ; < 0 = le Master doit à l'agent (crédit agent).
 * Fournisseur : solde > 0 = le Master doit au fournisseur ; < 0 = découvert (avance du Master), toujours signé « − ».
 * Mêmes formules que Agent::soldeTotals() et FournisseurDebtService (aucune seconde formule).
 */
class DebtHistoryService
{
    public const TYPES = ['all' => 'Agents et fournisseurs', 'agent' => 'Agents', 'fournisseur' => 'Fournisseurs'];

    public const STATUTS = ['' => 'Tous', 'dette' => 'Dette en cours', 'solde' => 'Soldé', 'credit' => 'Crédit / découvert'];

    /**
     * @param  array{q?: ?string, type?: ?string, statut?: ?string}  $filters
     * @return Collection<int, array<string, mixed>> plus récente activité en premier
     */
    public function rows(DateRange $range, array $filters = []): Collection
    {
        $type = $filters['type'] ?? 'all';
        $search = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $statut = (string) ($filters['statut'] ?? '');

        $rows = collect();
        if ($type !== 'fournisseur') {
            $rows = $rows->concat($this->agentRows($range));
        }
        if ($type !== 'agent') {
            $rows = $rows->concat($this->fournisseurRows($range));
        }

        return $rows
            ->filter(fn (array $r) => $search === '' || str_contains(mb_strtolower($r['nom']), $search))
            ->filter(fn (array $r) => match ($statut) {
                'dette' => $r['solde'] > 0,
                'solde' => abs($r['solde']) < 0.005,
                'credit' => $r['solde'] < 0,
                default => true,
            })
            ->sortByDesc(fn (array $r) => sprintf('%012d-%s', $r['date']?->getTimestamp() ?? 0, $r['nom']))
            ->values();
    }

    /**
     * Widgets : situation à la fin de la période.
     *
     * @return array{dettes_agents: float, credits_agents: float, dettes_fournisseurs: float, decouverts_fournisseurs: float, solde_net: float}
     */
    public function summary(DateRange $range): array
    {
        $agents = $this->agentRows($range);
        $fournisseurs = $this->fournisseurRows($range);

        $dettesAgents = $agents->where('solde', '>', 0)->sum('solde');
        $creditsAgents = $agents->where('solde', '<', 0)->sum('solde');
        $dettesFournisseurs = $fournisseurs->where('solde', '>', 0)->sum('solde');
        $decouverts = $fournisseurs->where('solde', '<', 0)->sum('solde');

        return [
            'dettes_agents' => round($dettesAgents, 2),
            'credits_agents' => round($creditsAgents, 2),
            'dettes_fournisseurs' => round($dettesFournisseurs, 2),
            'decouverts_fournisseurs' => round($decouverts, 2),
            // Créances du Master (agents débiteurs, avances chez les fournisseurs) moins ce qu'il doit (fournisseurs, agents créditeurs).
            'solde_net' => round($dettesAgents + $creditsAgents - $dettesFournisseurs - $decouverts, 2),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function agentRows(DateRange $range): Collection
    {
        $start = $range->start();
        $end = $range->endExclusive();

        $tx = fn (string $kind, ?Carbon $from, Carbon $to) => UvTransaction::query()
            ->where('transaction_type', $kind)->where('status', '!=', 'cancelled')
            ->when($from, fn (Builder $q) => $q->where('transaction_date', '>=', $from))
            ->where('transaction_date', '<', $to)
            ->groupBy('agent_id')->selectRaw('agent_id as id, SUM(amount) as total, MAX(transaction_date) as last')
            ->get()->keyBy('id');
        $paid = fn (?Carbon $from, Carbon $to) => Remboursement::query()
            ->when($from, fn (Builder $q) => $q->where('payment_date', '>=', $from))
            ->where('payment_date', '<', $to)
            ->groupBy('agent_id')->selectRaw('agent_id as id, SUM(amount) as total, MAX(payment_date) as last')
            ->get()->keyBy('id');

        $ventesAvant = $tx('distribution', null, $start);
        $retoursAvant = $tx('retour', null, $start);
        $paiementsAvant = $paid(null, $start);
        $ventes = $tx('distribution', $start, $end);
        $retours = $tx('retour', $start, $end);
        $paiements = $paid($start, $end);

        return Agent::query()->orderBy('name')->get(['id', 'name'])->map(function (Agent $agent) use ($ventesAvant, $retoursAvant, $paiementsAvant, $ventes, $retours, $paiements) {
            $v = fn (Collection $c) => (float) ($c[$agent->id]->total ?? 0);
            $ouverture = $v($ventesAvant) - $v($retoursAvant) - $v($paiementsAvant);
            $nouvelles = $v($ventes);
            $paye = $v($paiements) + $v($retours);

            return $this->row('agent', $agent->id, $agent->name, $ouverture, $nouvelles, $paye, $this->lastDate([$ventes, $retours, $paiements], $agent->id));
        })->filter(fn (array $r) => $this->isRelevant($r))->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function fournisseurRows(DateRange $range): Collection
    {
        $start = $range->start();
        $end = $range->endExclusive();

        $appro = fn (?Carbon $from, Carbon $to) => Approvisionnement::query()
            ->when($from, fn (Builder $q) => $q->where('date_approvisionnement', '>=', $from))
            ->where('date_approvisionnement', '<', $to)
            ->groupBy('fournisseur_id')->selectRaw('fournisseur_id as id, SUM(amount) as total, MAX(date_approvisionnement) as last')
            ->get()->keyBy('id');
        $remb = fn (?Carbon $from, Carbon $to) => RemboursementFournisseur::query()->actifs()
            ->when($from, fn (Builder $q) => $q->where('payment_date', '>=', $from))
            ->where('payment_date', '<', $to)
            ->groupBy('fournisseur_id')->selectRaw('fournisseur_id as id, SUM(amount) as total, MAX(payment_date) as last')
            ->get()->keyBy('id');

        $approAvant = $appro(null, $start);
        $rembAvant = $remb(null, $start);
        $approPeriode = $appro($start, $end);
        $rembPeriode = $remb($start, $end);

        return Fournisseur::query()->creditFournisseurs()->orderBy('name')->get(['id', 'name', 'type', 'agent_id'])->map(function (Fournisseur $f) use ($approAvant, $rembAvant, $approPeriode, $rembPeriode) {
            $v = fn (Collection $c) => (float) ($c[$f->id]->total ?? 0);

            $row = $this->row('fournisseur', $f->id, $f->name, $v($approAvant) - $v($rembAvant), $v($approPeriode), $v($rembPeriode), $this->lastDate([$approPeriode, $rembPeriode], $f->id));

            return $f->isAgentFournisseur() ? ['type_label' => 'Fournisseur (retour agent)'] + $row : $row;
        })->filter(fn (array $r) => $this->isRelevant($r))->values();
    }

    /** @return array<string, mixed> */
    private function row(string $type, int $id, string $nom, float $ouverture, float $nouvelles, float $paye, ?Carbon $date): array
    {
        $solde = round($ouverture + $nouvelles - $paye, 2);

        [$statut, $tone] = match (true) {
            $solde < 0 => [$type === 'agent' ? 'Crédit agent' : 'Découvert', 'ok'],
            abs($solde) < 0.005 => ['Soldé', 'muted'],
            $paye > 0 => ['Partiel', 'warn'],
            default => ['En cours', 'danger'],
        };

        return [
            'date' => $date,
            'type' => $type,
            'type_label' => $type === 'agent' ? 'Agent' : 'Fournisseur',
            'id' => $id,
            'nom' => $nom,
            'ouverture' => round($ouverture, 2),
            'nouvelles' => round($nouvelles, 2),
            'dette_initiale' => round($ouverture + $nouvelles, 2),
            'paye' => round($paye, 2),
            'solde' => $solde,
            'statut' => $statut,
            'tone' => $tone,
            'sens' => match (FournisseurDebtService::statutPour($solde)) {
                FournisseurDebtService::STATUT_DETTE => $type === 'agent' ? 'L\'agent doit au Master' : 'Le Master doit au fournisseur',
                FournisseurDebtService::STATUT_DECOUVERT => $type === 'agent' ? 'Le Master doit à l\'agent' : 'Avance du Master chez le fournisseur',
                default => 'Compte équilibré',
            },
        ];
    }

    /** Un compte sans solde ni mouvement sur la période n'apporte rien à l'historique. */
    private function isRelevant(array $row): bool
    {
        return abs($row['ouverture']) >= 0.005 || $row['nouvelles'] > 0 || $row['paye'] > 0 || abs($row['solde']) >= 0.005;
    }

    /** @param list<Collection> $sources */
    private function lastDate(array $sources, int $id): ?Carbon
    {
        $dates = collect($sources)->map(fn (Collection $c) => isset($c[$id]) ? Carbon::parse($c[$id]->last) : null)->filter();

        return $dates->isEmpty() ? null : $dates->sortByDesc(fn (Carbon $d) => $d->getTimestamp())->first();
    }
}
