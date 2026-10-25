<?php

namespace App\Services;

use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\RemboursementFournisseur;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Source unique de vérité pour le solde d'un fournisseur "Agent Premium" : ce type de fournisseur approvisionne à
 * crédit (livré avant d'être payé), contrairement à un fournisseur "Banque" (payé comptant, jamais de dette).
 *
 * Solde fournisseur SIGNÉ = Σ approvisionnements à crédit − Σ remboursements non annulés
 *   > 0 : dette du Master envers le fournisseur (rouge) ;
 *   < 0 : découvert fournisseur = avance versée par le Master, toujours affichée avec le signe « − » (vert) ;
 *   = 0 : compte équilibré.
 *
 * Un remboursement supérieur à la dette crée donc un découvert, et un approvisionnement à crédit ultérieur le
 * compense automatiquement (le solde signé remonte d'abord vers 0) : aucune écriture supplémentaire, aucune double
 * comptabilisation, et la compensation reste traçable dans history(). Aucun solde mis en cache : toujours recalculé
 * en direct depuis les mouvements réels, avec une arithmétique décimale exacte (bcmath, 2 décimales).
 */
class FournisseurDebtService
{
    public const STATUT_DETTE = 'dette';

    public const STATUT_DECOUVERT = 'decouvert';

    public const STATUT_EQUILIBRE = 'equilibre';

    public const STATUT_LABELS = [
        self::STATUT_DETTE => 'Dette',
        self::STATUT_DECOUVERT => 'Découvert',
        self::STATUT_EQUILIBRE => 'Équilibré',
    ];

    /** Fenêtre (secondes) pendant laquelle un paiement strictement identique est considéré comme un double envoi. */
    private const DOUBLE_SUBMIT_WINDOW = 30;

    /**
     * @return array{distribue: float, rembourse: float}
     */
    public function soldeTotals(Fournisseur $fournisseur, ?Carbon $asOf = null): array
    {
        [$distribue, $rembourse] = $this->exactTotals($fournisseur, $asOf);

        return [
            'distribue' => (float) $distribue,
            'rembourse' => (float) $rembourse,
        ];
    }

    /**
     * Solde signé d'un fournisseur (positif : dette ; négatif : découvert). Nom conservé pour compatibilité.
     */
    public function detteRestante(Fournisseur $fournisseur, ?Carbon $asOf = null): float
    {
        return (float) $this->soldeExact($fournisseur, $asOf);
    }

    /** Solde signé exact (chaîne décimale, 2 décimales). */
    public function soldeExact(Fournisseur $fournisseur, ?Carbon $asOf = null): string
    {
        [$distribue, $rembourse] = $this->exactTotals($fournisseur, $asOf);

        return bcsub($distribue, $rembourse, 2);
    }

    public static function statutPour(float|string $solde): string
    {
        return match (bccomp(self::decimal($solde), '0', 2)) {
            1 => self::STATUT_DETTE,
            -1 => self::STATUT_DECOUVERT,
            default => self::STATUT_EQUILIBRE,
        };
    }

    /**
     * Fiche financière d'un fournisseur, à une date donnée ou maintenant.
     *
     * @return array{
     *     credit: bool, total_approvisionne: float, total_credit: float, total_rembourse: float,
     *     solde: float, dette: float, decouvert: float, statut: string, statut_label: string,
     *     nb_approvisionnements: int, nb_remboursements: int, nb_annules: int,
     *     derniere_operation: array{date: CarbonInterface, libelle: string, reference: ?string, montant: float}|null
     * }
     */
    public function situation(Fournisseur $fournisseur, ?Carbon $asOf = null): array
    {
        $credit = $fournisseur->isCreditFournisseur();
        $appros = $fournisseur->approvisionnements()->when($asOf, fn ($q) => $q->where('date_approvisionnement', '<=', $asOf));
        $rembs = $fournisseur->remboursementsFournisseurs()->when($asOf, fn ($q) => $q->where('payment_date', '<=', $asOf));

        $totalApprovisionne = self::decimal((clone $appros)->sum('amount'));
        $totalRembourse = self::decimal((clone $rembs)->actifs()->sum('amount'));
        $totalCredit = $credit ? $totalApprovisionne : '0.00';
        $solde = bcsub($totalCredit, $totalRembourse, 2);
        $statut = self::statutPour($solde);

        $lastAppro = (clone $appros)->latest('date_approvisionnement')->latest('id')->first();
        $lastRemb = (clone $rembs)->latest('payment_date')->latest('id')->first();
        $derniere = collect([
            $lastAppro ? ['date' => $lastAppro->date_approvisionnement, 'libelle' => 'Approvisionnement', 'reference' => $lastAppro->reference, 'montant' => (float) $lastAppro->amount] : null,
            $lastRemb ? ['date' => $lastRemb->payment_date, 'libelle' => $lastRemb->isCancelled() ? 'Remboursement (annulé)' : 'Remboursement', 'reference' => $lastRemb->reference, 'montant' => (float) $lastRemb->amount] : null,
        ])->filter()->sortByDesc(fn ($op) => $op['date']->getTimestamp())->first();

        return [
            'credit' => $credit,
            'total_approvisionne' => (float) $totalApprovisionne,
            'total_credit' => (float) $totalCredit,
            'total_rembourse' => (float) $totalRembourse,
            'solde' => (float) $solde,
            'dette' => $statut === self::STATUT_DETTE ? (float) $solde : 0.0,
            'decouvert' => $statut === self::STATUT_DECOUVERT ? (float) $solde : 0.0,
            'statut' => $statut,
            'statut_label' => self::STATUT_LABELS[$statut],
            'nb_approvisionnements' => (clone $appros)->count(),
            'nb_remboursements' => (clone $rembs)->actifs()->count(),
            'nb_annules' => (clone $rembs)->whereNotNull('cancelled_at')->count(),
            'derniere_operation' => $derniere,
        ];
    }

    /**
     * Résumé global (tuiles Web et mobile) sur tous les fournisseurs à crédit. Dettes et découverts sont additionnés
     * fournisseur par fournisseur : l'avance versée à un fournisseur n'efface jamais la dette due à un autre.
     *
     * @return array{totalDette: float, totalRembourse: float, resteAPayer: float, totalDecouvert: float, soldeNet: float, nbEndettes: int, nbDecouverts: int}
     */
    public function globalSummary(?Carbon $asOf = null): array
    {
        $totalDette = '0.00';
        $totalRembourse = '0.00';
        $reste = '0.00';
        $decouvert = '0.00';
        $nbEndettes = 0;
        $nbDecouverts = 0;

        foreach ($this->soldesParFournisseur($asOf) as $ligne) {
            $totalDette = bcadd($totalDette, $ligne['credit'], 2);
            $totalRembourse = bcadd($totalRembourse, $ligne['rembourse'], 2);

            if (self::statutPour($ligne['solde']) === self::STATUT_DETTE) {
                $reste = bcadd($reste, $ligne['solde'], 2);
                $nbEndettes++;
            } elseif (self::statutPour($ligne['solde']) === self::STATUT_DECOUVERT) {
                $decouvert = bcadd($decouvert, $ligne['solde'], 2);
                $nbDecouverts++;
            }
        }

        return [
            'totalDette' => (float) $totalDette,
            'totalRembourse' => (float) $totalRembourse,
            'resteAPayer' => (float) $reste,
            'totalDecouvert' => (float) $decouvert,
            'soldeNet' => (float) bcadd($reste, $decouvert, 2),
            'nbEndettes' => $nbEndettes,
            'nbDecouverts' => $nbDecouverts,
        ];
    }

    /**
     * Solde de chaque fournisseur à crédit (requêtes groupées : 2 requêtes quel que soit le nombre de fournisseurs).
     * $since limite aux mouvements à partir de cette date (pour les mouvements d'une période).
     *
     * @return Collection<int, array{credit: string, rembourse: string, solde: string}> indexée par fournisseur_id
     */
    public function soldesParFournisseur(?Carbon $asOf = null, ?Carbon $since = null): Collection
    {
        $ids = Fournisseur::query()->creditFournisseurs()->pluck('id');

        $credits = Approvisionnement::query()->whereIn('fournisseur_id', $ids)
            ->when($asOf, fn ($q) => $q->where('date_approvisionnement', '<=', $asOf))
            ->when($since, fn ($q) => $q->where('date_approvisionnement', '>=', $since))
            ->groupBy('fournisseur_id')->selectRaw('fournisseur_id, SUM(amount) as total')->pluck('total', 'fournisseur_id');

        $rembourses = RemboursementFournisseur::query()->actifs()->whereIn('fournisseur_id', $ids)
            ->when($asOf, fn ($q) => $q->where('payment_date', '<=', $asOf))
            ->when($since, fn ($q) => $q->where('payment_date', '>=', $since))
            ->groupBy('fournisseur_id')->selectRaw('fournisseur_id, SUM(amount) as total')->pluck('total', 'fournisseur_id');

        return $ids->mapWithKeys(function (int $id) use ($credits, $rembourses) {
            $credit = self::decimal($credits[$id] ?? 0);
            $rembourse = self::decimal($rembourses[$id] ?? 0);

            return [$id => ['credit' => $credit, 'rembourse' => $rembourse, 'solde' => bcsub($credit, $rembourse, 2)]];
        });
    }

    /**
     * Historique chronologique d'un fournisseur avec solde courant : chaque ligne porte sa date, son auteur, son
     * type, sa référence et son impact signé sur le solde. Les compensations (approvisionnement absorbé par un
     * découvert) et les avances (remboursement au-delà de la dette) y sont expliquées. Un remboursement annulé
     * reste listé avec un impact nul et le statut « Annulé ».
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function history(Fournisseur $fournisseur, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $credit = $fournisseur->isCreditFournisseur();
        $solde = $from ? $this->soldeExact($fournisseur, $from->copy()->subSecond()) : '0.00';

        if (! $credit) {
            $avant = $fournisseur->remboursementsFournisseurs()->actifs()->when($from, fn ($q) => $q->where('payment_date', '<', $from))->sum('amount');
            $solde = $from ? bcsub('0', self::decimal($avant), 2) : '0.00';
        }

        $appros = $fournisseur->approvisionnements()->with('user:id,name')
            ->when($from, fn ($q) => $q->where('date_approvisionnement', '>=', $from))
            ->when($to, fn ($q) => $q->where('date_approvisionnement', '<=', $to))
            ->get()
            ->map(fn (Approvisionnement $a) => ['date' => $a->date_approvisionnement, 'order' => 0, 'id' => $a->id, 'model' => $a]);

        $rembs = $fournisseur->remboursementsFournisseurs()->with(['user:id,name', 'cancelledBy:id,name'])
            ->when($from, fn ($q) => $q->where('payment_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('payment_date', '<=', $to))
            ->get()
            ->map(fn (RemboursementFournisseur $r) => ['date' => $r->payment_date, 'order' => 1, 'id' => $r->id, 'model' => $r]);

        // Dates saisies à la minute : à date égale, l'ordre d'enregistrement départage (puis le type, puis l'id).
        $operations = $appros->concat($rembs)
            ->sortBy(fn ($op) => sprintf('%012d-%012d-%d-%012d', $op['date']->getTimestamp(), $op['model']->created_at?->getTimestamp() ?? 0, $op['order'], $op['id']))
            ->values();

        return $operations->map(function (array $op) use (&$solde, $credit) {
            $model = $op['model'];
            $avant = $solde;
            $montant = self::decimal($model->amount);
            $note = null;
            $statut = null;

            if ($model instanceof Approvisionnement) {
                $type = 'approvisionnement';
                $impact = $credit ? $montant : '0.00';
                $libelle = $credit ? 'Approvisionnement à crédit' : 'Approvisionnement payé comptant';
                $statut = $credit ? null : 'Payé';

                if ($credit && bccomp($avant, '0', 2) < 0) {
                    $disponible = bcmul($avant, '-1', 2);
                    $compense = bccomp($montant, $disponible, 2) <= 0 ? $montant : $disponible;
                    $note = 'Dont '.self::fcfa($compense).' compensés par le découvert';
                }
            } elseif ($model->isCancelled()) {
                $type = 'remboursement';
                $libelle = 'Remboursement';
                $impact = '0.00';
                $statut = 'Annulé';
                $note = 'Annulé le '.$model->cancelled_at->format('d/m/Y H:i')
                    .($model->cancelledBy ? ' par '.$model->cancelledBy->name : '')
                    .($model->cancellation_reason ? ' — '.$model->cancellation_reason : '');
            } else {
                $type = 'remboursement';
                $libelle = 'Remboursement';
                $impact = bcmul($montant, '-1', 2);
                $apres = bcadd($avant, $impact, 2);

                if (bccomp($apres, '0', 2) < 0) {
                    $avance = bccomp($avant, '0', 2) > 0 ? bcmul($apres, '-1', 2) : $montant;
                    $note = 'Dont '.self::fcfa($avance).' en avance (découvert)';
                }
            }

            $solde = bcadd($avant, $impact, 2);

            return [
                'date' => $op['date'],
                'type' => $type,
                'libelle' => $libelle,
                'reference' => $model->reference,
                'auteur' => $model->user?->name,
                'montant' => (float) $montant,
                'impact' => (float) $impact,
                'solde_avant' => (float) $avant,
                'solde_apres' => (float) $solde,
                'statut' => $statut ?? self::STATUT_LABELS[self::statutPour($solde)],
                'annule' => $model instanceof RemboursementFournisseur && $model->isCancelled(),
                'note' => $note,
                'model' => $model,
            ];
        });
    }

    /**
     * Point d'entrée unique pour enregistrer un remboursement fournisseur. Un montant supérieur à la dette est
     * accepté : l'excédent devient un découvert (avance du Master), compensé par les approvisionnements à crédit
     * suivants. Le fournisseur est verrouillé pendant la transaction et un double envoi identique est refusé.
     *
     * @throws ValidationException
     */
    public function process(
        Fournisseur $fournisseur,
        float $amount,
        string $paymentMethod = 'cash',
        ?string $paymentDate = null,
        ?string $notes = null,
        ?string $reference = null,
    ): RemboursementFournisseur {
        $this->validateAmount($fournisseur, $amount);

        return DB::transaction(function () use ($fournisseur, $amount, $paymentMethod, $paymentDate, $notes, $reference) {
            Fournisseur::query()->whereKey($fournisseur->getKey())->lockForUpdate()->first();

            if ($paymentDate !== null && $this->isDoubleSubmit($fournisseur, $amount, $paymentDate)) {
                throw ValidationException::withMessages([
                    'amount' => 'Ce paiement vient déjà d\'être enregistré (double envoi évité). Vérifiez l\'historique des paiements.',
                ]);
            }

            return RemboursementFournisseur::create([
                'fournisseur_id' => $fournisseur->id,
                'user_id' => Auth::id(),
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'reference' => $reference,
                'payment_date' => $paymentDate ?? now(),
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Seule règle de montant : strictement positif. Un montant supérieur à la dette n'est plus refusé, il crée un
     * découvert (avance) — voir la documentation de la classe.
     *
     * @throws ValidationException
     */
    public function validateAmount(Fournisseur $fournisseur, float $amount): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Le montant du remboursement doit être supérieur à 0.',
            ]);
        }
    }

    /**
     * Annule un remboursement fournisseur saisi par erreur (contre-passation logique) : la ligne d'origine est
     * conservée avec le statut « Annulé », son auteur, sa date et son motif ; elle sort du solde, qui est donc
     * recalculé immédiatement partout (même source). Atomique, verrouillée, et jamais deux fois.
     *
     * Opérations dépendantes : un remboursement fournisseur n'alimente ni le stock UV, ni la caisse, ni les
     * commissions ; seule la dette du fournisseur en dépend, et elle est recalculée en direct. Le fournisseur
     * doit toutefois toujours exister (un fournisseur supprimé fige son historique).
     *
     * @throws ValidationException
     */
    public function cancel(RemboursementFournisseur $remboursement, string $reason, ?User $by = null): RemboursementFournisseur
    {
        $reason = trim($reason);
        $by ??= Auth::user();

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Indiquez le motif de l\'annulation (3 caractères minimum).']);
        }

        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Le motif ne peut pas dépasser 500 caractères.']);
        }

        return DB::transaction(function () use ($remboursement, $reason, $by) {
            $locked = RemboursementFournisseur::query()->whereKey($remboursement->getKey())->lockForUpdate()->first();

            if (! $locked) {
                throw ValidationException::withMessages(['reason' => 'Ce remboursement est introuvable.']);
            }

            if ($locked->isCancelled()) {
                throw ValidationException::withMessages(['reason' => 'Ce remboursement a déjà été annulé le '.$locked->cancelled_at->format('d/m/Y à H:i').'.']);
            }

            $fournisseur = $locked->fournisseur;

            if (! $fournisseur) {
                throw ValidationException::withMessages(['reason' => 'Le fournisseur de ce remboursement a été supprimé : annulation impossible.']);
            }

            Fournisseur::query()->whereKey($fournisseur->getKey())->lockForUpdate()->first();
            $soldeAvant = $this->soldeExact($fournisseur);

            $locked->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $by?->getKey(),
                'cancellation_reason' => $reason,
            ])->save();

            $soldeApres = $this->soldeExact($fournisseur);

            activity('remboursements_fournisseurs')
                ->performedOn($locked)
                ->causedBy($by)
                ->event('cancelled')
                ->withProperties([
                    'reference' => $locked->reference,
                    'fournisseur' => $fournisseur->name,
                    'montant' => (float) $locked->amount,
                    'date_paiement' => $locked->payment_date?->toDateTimeString(),
                    'motif' => $reason,
                    'solde_avant' => (float) $soldeAvant,
                    'solde_apres' => (float) $soldeApres,
                ])
                ->log("Remboursement fournisseur annulé: {$locked->reference}");

            return $locked->fresh(['fournisseur', 'cancelledBy']);
        });
    }

    /** @return array{0: string, 1: string} [Σ approvisionnements, Σ remboursements non annulés] en décimal exact */
    private function exactTotals(Fournisseur $fournisseur, ?Carbon $asOf = null): array
    {
        $approQuery = $fournisseur->approvisionnements();
        $rembQuery = $fournisseur->remboursementsFournisseurs()->actifs();

        if ($asOf) {
            $approQuery->where('date_approvisionnement', '<=', $asOf);
            $rembQuery->where('payment_date', '<=', $asOf);
        }

        return [self::decimal($approQuery->sum('amount')), self::decimal($rembQuery->sum('amount'))];
    }

    private function isDoubleSubmit(Fournisseur $fournisseur, float $amount, string $paymentDate): bool
    {
        return RemboursementFournisseur::query()->actifs()
            ->where('fournisseur_id', $fournisseur->getKey())
            ->where('user_id', Auth::id())
            ->where('amount', $amount)
            ->where('payment_date', Carbon::parse($paymentDate))
            ->where('created_at', '>=', now()->subSeconds(self::DOUBLE_SUBMIT_WINDOW))
            ->exists();
    }

    /** Somme SQL (chaîne, entier ou flottant selon le pilote) → décimal exact à 2 chiffres pour bcmath. */
    public static function decimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        if (is_float($value) || is_int($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        return bcadd((string) $value, '0', 2);
    }

    private static function fcfa(string $amount): string
    {
        return number_format((float) $amount, 0, ',', ' ').' FCFA';
    }
}
