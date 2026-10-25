<?php

namespace App\Livewire\Concerns;

use App\Models\RemboursementFournisseur;
use App\Services\FournisseurDebtService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * « Annuler le remboursement » sur mobile (historique des paiements, fiche fournisseur) : mêmes règles que le Web,
 * via FournisseurDebtService::cancel() (motif obligatoire, atomique, audité, jamais deux fois). Un identifiant d'une
 * autre entreprise est introuvable (scope d'entreprise) ; l'autorisation est revérifiée côté serveur à chaque appel.
 */
trait CancelsSupplierPayments
{
    public string $cancelReason = '';

    public function cancelPayment(int $id): void
    {
        $remboursement = RemboursementFournisseur::query()->findOrFail($id);
        Gate::authorize('cancel', $remboursement);

        try {
            app(FournisseurDebtService::class)->cancel($remboursement, $this->cancelReason, auth()->user());
        } catch (ValidationException $e) {
            $this->dispatch('m-toast', message: collect($e->errors())->flatten()->implode(' '), type: 'error');

            return;
        }

        $this->cancelReason = '';
        $this->dispatch('m-toast', message: 'Remboursement '.$remboursement->reference.' annulé. Le solde du fournisseur a été recalculé.');
    }
}
