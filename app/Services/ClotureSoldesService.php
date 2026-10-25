<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\ClotureJournaliere;
use App\Models\ClotureSolde;
use App\Models\Fournisseur;
use App\Models\Remboursement;
use App\Models\RemboursementFournisseur;
use App\Models\Tenant;
use App\Models\UvTransaction;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Clôture quotidienne des soldes (historique des dettes et crédits) : à la fin de chaque journée civile
 * (00:00 → 23:59:59, Africa/Abidjan), photographie des agents et fournisseurs dont le solde est non nul.
 *
 * Règles :
 *  - le solde d'une journée est celui de TOUS les mouvements datés de cette journée ou avant (strictement avant
 *    00:00 le lendemain) : la clôture est donc enregistrée juste après minuit, une opération de 23:59:59 y figure ;
 *  - formules identiques à Agent::soldeTotals() (distribué − retourné − remboursé) et à FournisseurDebtService
 *    (approvisionnements à crédit − remboursements non annulés), calcul décimal exact ;
 *  - une clôture par entreprise et par date (contrainte unique + verrou) : relancer ne crée jamais de doublon ;
 *  - une clôture enregistrée n'est JAMAIS recalculée : un remboursement le lendemain ne modifie pas la veille ;
 *  - rattrapage : toute journée terminée sans clôture (panne, cron arrêté) est reconstituée depuis les mouvements
 *    enregistrés, de la plus ancienne à la plus récente (source « rattrapage ») — jamais avant la première donnée
 *    de l'entreprise : aucun solde n'est inventé ;
 *  - correction rétroactive (opération antidatée, modifiée ou annulée après la clôture) : verifier() compare les
 *    soldes figés à la reconstitution ; un écart est signalé sur la clôture et journalisé, les soldes figés ne
 *    changent pas. Les clôtures suivantes intègrent naturellement la correction.
 */
class ClotureSoldesService
{
    /** Garde-fou de durée d'une exécution ; les journées restantes sont rattrapées à l'exécution suivante. */
    public const MAX_JOURS_PAR_EXECUTION = 1000;

    /** Nombre de jours de clôtures contrôlés par verifier(). */
    public const JOURS_VERIFIES = 31;

    /**
     * Clôture chaque journée terminée qui n'a pas encore de clôture, de $depuis (ou de la première donnée de
     * l'entreprise) jusqu'à $jusqua (au plus tard hier).
     *
     * @return list<string> dates clôturées (AAAA-MM-JJ)
     */
    public function cloturerJourneesManquantes(?CarbonImmutable $jusqua = null, ?CarbonImmutable $depuis = null): array
    {
        $lock = Cache::lock('clotures-soldes:'.TenantContext::requireId(), 900);
        if (! $lock->get()) {
            return [];
        }

        try {
            $manquantes = $this->journeesManquantes($jusqua, $depuis);
            if ($manquantes === []) {
                return [];
            }

            $etat = $this->soldesParJour(self::jour(reset($manquantes)), self::jour(end($manquantes)));
            $maintenant = CarbonImmutable::now(config('app.timezone'));
            $faites = [];

            foreach ($manquantes as $date) {
                if ($this->enregistrer($date, $etat['jours'][$date], $etat['comptes'], $maintenant)) {
                    $faites[] = $date;
                }
            }

            return $faites;
        } finally {
            $lock->release();
        }
    }

    /**
     * Compare les clôtures des $jours derniers jours à la reconstitution actuelle depuis les mouvements. Les soldes
     * figés ne sont jamais modifiés : seul l'en-tête porte l'écart constaté (date, comptes, soldes figé / recalculé).
     *
     * @return int nombre de clôtures présentant un écart
     */
    public function verifier(int $jours = self::JOURS_VERIFIES): int
    {
        $depuis = CarbonImmutable::today(config('app.timezone'))->subDays($jours)->toDateString();
        $clotures = ClotureJournaliere::query()->where('date', '>=', $depuis)->orderBy('date')->get();
        if ($clotures->isEmpty()) {
            return 0;
        }

        $etat = $this->soldesParJour($clotures->first()->jour()->toImmutable(), $clotures->last()->jour()->toImmutable());
        $figes = ClotureSolde::query()->whereIn('cloture_id', $clotures->modelKeys())
            ->get(['cloture_id', 'compte_type', 'compte_id', 'nom', 'solde'])->groupBy('cloture_id');
        $maintenant = now();
        $ecarts = 0;

        foreach ($clotures as $cloture) {
            $lignes = ($figes[$cloture->id] ?? collect())->mapWithKeys(fn (ClotureSolde $l) => [$l->compte_type.':'.$l->compte_id => ['nom' => $l->nom, 'solde' => (string) $l->solde]]);
            $recalcules = $etat['jours'][$cloture->jour()->toDateString()] ?? [];
            $details = [];

            foreach (array_unique([...$lignes->keys()->all(), ...array_keys($recalcules)]) as $cle) {
                $fige = $lignes[$cle]['solde'] ?? '0.00';
                $recalcule = $recalcules[$cle] ?? '0.00';
                if (bccomp($fige, $recalcule, 2) !== 0) {
                    $details[] = ['compte' => $cle, 'nom' => $lignes[$cle]['nom'] ?? $etat['comptes'][$cle]['nom'] ?? $cle, 'solde_cloture' => $fige, 'solde_recalcule' => $recalcule];
                }
            }

            $avant = $cloture->ecart_details ?? [];
            $cloture->verifiee_at = $maintenant;
            if ($details !== [] && $details !== $avant) {
                $cloture->ecart_constate_at ??= $maintenant;
                $cloture->ecart_details = $details;
                $this->journaliser($cloture, 'cloture_ecart', 'Écart constaté après la clôture du '.$cloture->jour()->format('d/m/Y').' : soldes figés conservés', $details);
            } elseif ($details === [] && $avant !== []) {
                $cloture->ecart_constate_at = null;
                $cloture->ecart_details = null;
                $this->journaliser($cloture, 'cloture_ecart_resolu', 'Écart résorbé sur la clôture du '.$cloture->jour()->format('d/m/Y'), $avant);
            }
            $cloture->save();
            $ecarts += $details !== [] ? 1 : 0;
        }

        return $ecarts;
    }

    /**
     * Soldes à la fin de chaque journée de [$du, $au], reconstitués depuis les mouvements : solde d'ouverture
     * (tout ce qui précède $du) puis variations jour par jour. Un compte supprimé (suppression logique) figure
     * dans les journées où il existait encore.
     *
     * @return array{comptes: array<string, array<string, mixed>>, jours: array<string, array<string, string>>}
     *                                                                                                          jours : 'AAAA-MM-JJ' => ['agent:12' => '350000.00', …] (soldes non nuls uniquement)
     */
    public function soldesParJour(CarbonImmutable $du, CarbonImmutable $au): array
    {
        $debut = $du->startOfDay();
        $finExclue = $au->startOfDay()->addDay();

        $comptes = [];
        foreach (Agent::withTrashed()->get(['id', 'name', 'deleted_at']) as $agent) {
            $comptes['agent:'.$agent->id] = ['type' => ClotureSolde::TYPE_AGENT, 'id' => $agent->id, 'nom' => $agent->name, 'type_label' => 'Agent', 'supprime' => $agent->deleted_at?->toDateTimeString()];
        }
        foreach (Fournisseur::withTrashed()->creditFournisseurs()->get(['id', 'name', 'deleted_at']) as $fournisseur) {
            $comptes['fournisseur:'.$fournisseur->id] = ['type' => ClotureSolde::TYPE_FOURNISSEUR, 'id' => $fournisseur->id, 'nom' => $fournisseur->name, 'type_label' => 'Fournisseur', 'supprime' => $fournisseur->deleted_at?->toDateTimeString()];
        }

        $ouverture = [];
        $variations = [];
        $sources = [
            ['agent', 'agent_id', 'transaction_date', '1', UvTransaction::query()->where('transaction_type', 'distribution')->where('status', '!=', 'cancelled')],
            ['agent', 'agent_id', 'transaction_date', '-1', UvTransaction::query()->where('transaction_type', 'retour')->where('status', '!=', 'cancelled')],
            ['agent', 'agent_id', 'payment_date', '-1', Remboursement::query()],
            ['fournisseur', 'fournisseur_id', 'date_approvisionnement', '1', Approvisionnement::query()],
            ['fournisseur', 'fournisseur_id', 'payment_date', '-1', RemboursementFournisseur::query()->actifs()],
        ];

        foreach ($sources as [$type, $colonneCompte, $colonneDate, $signe, $query]) {
            $this->cumuler((clone $query)->where($colonneDate, '<', $debut), $colonneCompte, null, $type, $signe, $comptes, function (string $cle, string $montant) use (&$ouverture) {
                $ouverture[$cle] = bcadd($ouverture[$cle] ?? '0', $montant, 2);
            });
            $this->cumuler((clone $query)->where($colonneDate, '>=', $debut)->where($colonneDate, '<', $finExclue), $colonneCompte, $colonneDate, $type, $signe, $comptes, function (string $cle, string $montant, string $jour) use (&$variations) {
                $variations[$jour][$cle] = bcadd($variations[$jour][$cle] ?? '0', $montant, 2);
            });
        }

        $soldes = $ouverture;
        $jours = [];
        for ($jour = $debut; $jour->lessThan($finExclue); $jour = $jour->addDay()) {
            $date = $jour->toDateString();
            foreach ($variations[$date] ?? [] as $cle => $montant) {
                $soldes[$cle] = bcadd($soldes[$cle] ?? '0', $montant, 2);
            }

            $finJournee = $jour->addDay()->toDateTimeString();
            $jours[$date] = array_filter($soldes, fn (string $solde, string $cle) => bccomp($solde, '0', 2) !== 0
                && ($comptes[$cle]['supprime'] === null || $comptes[$cle]['supprime'] >= $finJournee), ARRAY_FILTER_USE_BOTH);
            ksort($jours[$date]);
        }

        return ['comptes' => $comptes, 'jours' => $jours];
    }

    /** @return list<string> journées terminées sans clôture, de la plus ancienne à la plus récente (bornées) */
    private function journeesManquantes(?CarbonImmutable $jusqua, ?CarbonImmutable $depuis): array
    {
        $origine = $this->premiereDonnee();
        if ($origine === null) {
            return [];
        }

        $hier = CarbonImmutable::today(config('app.timezone'))->subDay();
        $fin = $jusqua && $jusqua->startOfDay()->lessThan($hier) ? $jusqua->startOfDay() : $hier;
        $debut = $depuis && $depuis->startOfDay()->greaterThan($origine) ? $depuis->startOfDay() : $origine;
        if ($debut->greaterThan($fin)) {
            return [];
        }

        $existantes = ClotureJournaliere::query()->whereBetween('date', [$debut->toDateString(), $fin->toDateString()])
            ->toBase()->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->flip();

        $manquantes = [];
        for ($jour = $debut; $jour->lessThanOrEqualTo($fin) && count($manquantes) < self::MAX_JOURS_PAR_EXECUTION; $jour = $jour->addDay()) {
            if (! isset($existantes[$jour->toDateString()])) {
                $manquantes[] = $jour->toDateString();
            }
        }

        return $manquantes;
    }

    /**
     * Première journée pour laquelle l'entreprise a des données : la plus ancienne opération enregistrée, ou sa
     * création. Avant, aucune clôture n'est reconstituée.
     */
    private function premiereDonnee(): ?CarbonImmutable
    {
        $dates = collect([
            UvTransaction::withTrashed()->min('transaction_date'),
            Remboursement::withTrashed()->min('payment_date'),
            Approvisionnement::withTrashed()->min('date_approvisionnement'),
            RemboursementFournisseur::withTrashed()->min('payment_date'),
            Tenant::query()->whereKey(TenantContext::requireId())->value('created_at'),
        ])->filter()->map(fn ($d) => CarbonImmutable::parse($d, config('app.timezone'))->startOfDay());

        return $dates->isEmpty() ? null : $dates->sortBy(fn (CarbonImmutable $d) => $d->getTimestamp())->first();
    }

    /**
     * Enregistre la clôture d'une journée (en-tête + lignes) dans une seule transaction. false si elle existe déjà
     * (exécution concurrente : la contrainte unique tranche, aucun doublon).
     *
     * @param  array<string, string>  $soldes
     */
    private function enregistrer(string $date, array $soldes, array $comptes, CarbonImmutable $maintenant): bool
    {
        $lignes = [];
        $totaux = [ClotureSolde::NATURE_DETTE => ['nb' => 0, 'total' => '0'], ClotureSolde::NATURE_CREDIT => ['nb' => 0, 'total' => '0']];

        foreach ($soldes as $cle => $solde) {
            $compte = $comptes[$cle];
            $nature = ClotureSolde::natureFor($compte['type'], $solde);
            $montant = ltrim($solde, '-');
            $totaux[$nature]['nb']++;
            $totaux[$nature]['total'] = bcadd($totaux[$nature]['total'], $montant, 2);
            $lignes[] = [
                'date' => $date, 'compte_type' => $compte['type'], 'compte_id' => $compte['id'], 'nom' => mb_substr($compte['nom'], 0, 255),
                'type_label' => $compte['type_label'], 'solde' => $solde, 'nature' => $nature, 'montant' => $montant,
            ];
        }

        try {
            return DB::transaction(function () use ($date, $lignes, $totaux, $maintenant) {
                if (ClotureJournaliere::query()->where('date', $date)->exists()) {
                    return false;
                }

                $cloture = ClotureJournaliere::create([
                    'date' => $date,
                    'closed_at' => $maintenant,
                    'source' => self::jour($date)->addDay()->isSameDay($maintenant) ? ClotureJournaliere::SOURCE_AUTOMATIQUE : ClotureJournaliere::SOURCE_RATTRAPAGE,
                    'nb_dettes' => $totaux[ClotureSolde::NATURE_DETTE]['nb'],
                    'nb_credits' => $totaux[ClotureSolde::NATURE_CREDIT]['nb'],
                    'total_dettes' => $totaux[ClotureSolde::NATURE_DETTE]['total'],
                    'total_credits' => $totaux[ClotureSolde::NATURE_CREDIT]['total'],
                ]);

                // Insertion groupée (performances) : tenant_id vient du contexte serveur, comme pour le modèle.
                $commun = ['tenant_id' => $cloture->tenant_id, 'cloture_id' => $cloture->id, 'created_at' => $maintenant, 'updated_at' => $maintenant];
                foreach (array_chunk($lignes, 500) as $paquet) {
                    DB::table('cloture_soldes')->insert(array_map(fn (array $l) => $l + $commun, $paquet));
                }

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * Agrège une source de mouvements par compte (et par jour si $colonneDate) et transmet chaque montant signé.
     * Les comptes inconnus (fournisseur payé comptant, agent purgé) sont ignorés.
     */
    private function cumuler(Builder $query, string $colonneCompte, ?string $colonneDate, string $type, string $signe, array $comptes, callable $ajouter): void
    {
        $query->toBase()
            ->selectRaw($colonneCompte.' as compte, SUM(amount) as total'.($colonneDate ? ', DATE('.$colonneDate.') as jour' : ''))
            ->groupBy($colonneCompte)
            ->when($colonneDate, fn ($q) => $q->groupBy(DB::raw('DATE('.$colonneDate.')')))
            ->get()
            ->each(function (object $ligne) use ($type, $signe, $comptes, $ajouter, $colonneDate) {
                $cle = $type.':'.$ligne->compte;
                if (! isset($comptes[$cle])) {
                    return;
                }

                $montant = bcmul(FournisseurDebtService::decimal($ligne->total), $signe, 2);
                $colonneDate ? $ajouter($cle, $montant, substr((string) $ligne->jour, 0, 10)) : $ajouter($cle, $montant);
            });
    }

    private function journaliser(ClotureJournaliere $cloture, string $event, string $message, array $details): void
    {
        activity('clotures')
            ->performedOn($cloture)
            ->event($event)
            ->withProperties(['journee' => $cloture->jour()->toDateString(), 'ecarts' => $details])
            ->log($message);
    }

    private static function jour(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, config('app.timezone'));
    }
}
