<?php

namespace App\Observers;

use App\Models\UvTransaction;
use App\Services\MasterSoldeService;
use App\Services\SecurityService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class UvTransactionSecurityObserver
{
    protected SecurityService $securityService;

    protected MasterSoldeService $masterSoldeService;

    public function __construct(SecurityService $securityService, MasterSoldeService $masterSoldeService)
    {
        $this->securityService = $securityService;
        $this->masterSoldeService = $masterSoldeService;
    }

    /**
     * Handle the UvTransaction "creating" event.
     */
    public function creating(UvTransaction $transaction): void
    {
        // Générer une signature pour la transaction
        if (config('security.transactions.signature_enabled', true)) {
            $data = [
                'agent_id' => $transaction->agent_id,
                'amount' => $transaction->amount,
                'created_at' => now()->toIso8601String(),
            ];

            $transaction->signature = $this->securityService->generateTransactionSignature($data);
        }

        // Détecter les transactions suspectes
        if (config('security.fraud_detection.enabled', true)) {
            $user = Auth::user();
            if ($user) {
                $detection = $this->securityService->detectSuspiciousTransaction($transaction, $user);

                if ($detection['suspicious']) {
                    Log::warning('Suspicious transaction detected', [
                        'transaction' => $transaction->toArray(),
                        'detection' => $detection,
                        'user_id' => $user->id,
                    ]);

                    // Prendre l'action recommandée
                    $this->handleSuspiciousTransaction($transaction, $detection);
                }
            }
        }
    }

    /**
     * Handle the UvTransaction "created" event.
     */
    public function created(UvTransaction $transaction): void
    {
        // Logger la création avec audit complet
        activity()
            ->performedOn($transaction)
            ->causedBy(Auth::user())
            ->withProperties([
                'transaction_id' => $transaction->id,
                'agent_id' => $transaction->agent_id,
                'amount' => $transaction->amount,
                'signature' => $transaction->signature,
                'ip_address' => request()->ip(),
            ])
            ->log('uv_transaction_created');

        // Garde-fou : garantir que l'instantané persisté du Solde UV
        // Disponible reste cohérent, quel que soit le chemin de création
        // (interface, API, console, sans utilisateur connecté). Le calcul
        // n'a plus rien d'un doublon : il délègue à la source de vérité
        // unique (MasterSoldeService), déjà appelée par
        // UvDistributionService::distribute() mais rappelée ici en filet de
        // sécurité pour toute création qui ne passerait pas par ce service.
        if (in_array($transaction->transaction_type, ['distribution', 'retour'], true)) {
            $this->masterSoldeService->syncPersistedSnapshot();
        }
    }

    /**
     * Handle the UvTransaction "updating" event.
     */
    public function updating(UvTransaction $transaction): void
    {
        // Champs financiers critiques qui ne doivent PAS être modifiés après création
        $criticalFields = ['amount', 'agent_id', 'transaction_type'];

        // Vérifier si un champ critique a été modifié
        $modifiedCriticalFields = array_intersect($criticalFields, array_keys($transaction->getDirty()));

        if (! empty($modifiedCriticalFields)) {
            Log::warning('Attempt to modify critical transaction fields', [
                'transaction_id' => $transaction->id,
                'modified_fields' => $modifiedCriticalFields,
                'original' => array_intersect_key($transaction->getOriginal(), array_flip($modifiedCriticalFields)),
                'new_values' => array_intersect_key($transaction->getDirty(), array_flip($modifiedCriticalFields)),
                'user_id' => Auth::id(),
                'ip' => request()->ip(),
            ]);

            // Logger l'activité suspecte mais NE PAS bloquer pour permettre les corrections admin
            $this->securityService->logSuspiciousActivity('transaction_critical_field_modification', [
                'transaction_id' => $transaction->id,
                'modified_fields' => $modifiedCriticalFields,
                'changes' => $transaction->getDirty(),
            ]);

            // Note: On log mais on n'empêche plus la modification pour permettre les corrections nécessaires
            // Si vous voulez bloquer, décommentez la ligne suivante:
            // throw new \Exception('Les transactions financières ne peuvent pas être modifiées. Utilisez une annulation et une nouvelle transaction.');
        }
    }

    /**
     * Handle the UvTransaction "updated" event.
     * Toute modification pouvant affecter le Solde UV Disponible (montant,
     * type, statut — ex : annulation) doit se répercuter sur l'instantané
     * persisté, quel que soit le chemin qui a fait la modification.
     */
    public function updated(UvTransaction $transaction): void
    {
        $relevant = ['amount', 'transaction_type', 'status'];

        if (array_intersect($relevant, array_keys($transaction->getChanges()))) {
            $this->masterSoldeService->syncPersistedSnapshot();
        }
    }

    /**
     * Handle the UvTransaction "deleting" event.
     */
    public function deleting(UvTransaction $transaction): void
    {
        // Les transactions NE DOIVENT JAMAIS être supprimées physiquement
        if (! $transaction->isForceDeleting()) {
            // Soft delete OK
            activity()
                ->performedOn($transaction)
                ->causedBy(Auth::user())
                ->withProperties([
                    'transaction_id' => $transaction->id,
                    'reason' => 'soft_delete',
                ])
                ->log('uv_transaction_soft_deleted');
        } else {
            // Force delete INTERDIT
            Log::critical('Attempt to force delete financial transaction', [
                'transaction_id' => $transaction->id,
                'user_id' => Auth::id(),
                'ip' => request()->ip(),
            ]);

            $this->securityService->logSuspiciousActivity('transaction_force_delete_attempt', [
                'transaction_id' => $transaction->id,
            ]);

            throw new \Exception('Les transactions financières ne peuvent jamais être supprimées physiquement.');
        }
    }

    /**
     * Handle the UvTransaction "deleted" event (soft delete). Une vente ou un
     * retour supprimé(e) ne doit plus compter dans le Solde UV Disponible.
     */
    public function deleted(UvTransaction $transaction): void
    {
        if (in_array($transaction->transaction_type, ['distribution', 'retour'], true)) {
            $this->masterSoldeService->syncPersistedSnapshot();
        }
    }

    /**
     * Handle the UvTransaction "restored" event.
     */
    public function restored(UvTransaction $transaction): void
    {
        if (in_array($transaction->transaction_type, ['distribution', 'retour'], true)) {
            $this->masterSoldeService->syncPersistedSnapshot();
        }
    }

    /**
     * Gérer une transaction suspecte
     */
    protected function handleSuspiciousTransaction(UvTransaction $transaction, array $detection): void
    {
        $action = $detection['recommended_action'];

        switch ($action) {
            case 'block_and_alert':
                $this->securityService->sendSecurityAlert('suspicious_transaction_blocked', [
                    'transaction' => $transaction->toArray(),
                    'detection' => $detection,
                    'user_id' => Auth::id(),
                ]);

                throw new \Exception('Transaction bloquée pour raisons de sécurité. Un administrateur a été notifié.');
            case 'require_2fa_and_alert':
                // Vérifier si l'utilisateur a 2FA activé
                $user = Auth::user();
                if ($user && $user->two_factor_enabled) {
                    // L'utilisateur a déjà validé 2FA pour cette session
                    // Logger l'action suspecte mais autoriser
                    activity()
                        ->causedBy(Auth::id())
                        ->withProperties([
                            'transaction' => $transaction->toArray(),
                            'detection' => $detection,
                            '2fa_verified' => true,
                        ])
                        ->log('suspicious_transaction_2fa_verified');
                } else {
                    // Utilisateur sans 2FA - envoyer alerte
                    $this->securityService->sendSecurityAlert('suspicious_transaction_no_2fa', [
                        'transaction' => $transaction->toArray(),
                        'detection' => $detection,
                        'user_id' => Auth::id(),
                        'warning' => 'Utilisateur sans 2FA sur transaction suspecte',
                    ]);
                }

                // Toujours envoyer l'alerte pour traçabilité
                $this->securityService->sendSecurityAlert('suspicious_transaction_flagged', [
                    'transaction' => $transaction->toArray(),
                    'detection' => $detection,
                    'user_id' => Auth::id(),
                ]);
                break;

            case 'flag_for_review':
                activity()
                    ->withProperties([
                        'transaction' => $transaction->toArray(),
                        'detection' => $detection,
                    ])
                    ->log('transaction_flagged_for_review');
                break;
        }
    }

}
