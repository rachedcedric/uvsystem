<?php

namespace App\Observers;

use App\Models\Approvisionnement;
use App\Models\UvTransaction;
use App\Services\LedgerCascadeService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Détecte toute modification d'une vente/retour (UvTransaction) ou d'un
 * approvisionnement qui invalide le grand livre partagé
 * (solde_uv_avant/apres) et déclenche sa reconstruction chronologique à
 * partir du point affecté (App\Services\LedgerCascadeService).
 *
 * Un seul observer générique couvre tous les chemins d'édition Filament
 * (page Edit dédiée, RelationManager sur la fiche agent, artisan, import)
 * puisqu'il réagit aux événements du modèle plutôt qu'à un point d'entrée
 * UI précis — voir TransactionsRelationManager, qui édite/supprime via des
 * actions Filament génériques jamais interceptées par une page Edit.
 *
 * Enregistré pour UvTransaction ET Approvisionnement dans
 * AppServiceProvider::boot().
 */
class LedgerCascadeObserver
{
    protected const RELEVANT_UV_FIELDS = ['amount', 'transaction_type', 'status', 'transaction_date'];

    protected const RELEVANT_APPRO_FIELDS = ['amount', 'date_approvisionnement'];

    public function created(Model $model): void
    {
        // Vente/retour ou approvisionnement antidaté(e) : si des mouvements
        // postérieurs existent déjà, leurs instantanés solde_avant/apres
        // sont désormais faux et doivent être reconstruits à partir d'ici.
        $date = $this->movementDate($model);

        if ($date !== null && $this->hasLaterMovements($date)) {
            $this->service()->rebuildFrom($date);
        }
    }

    public function updated(Model $model): void
    {
        $fields = $model instanceof UvTransaction ? self::RELEVANT_UV_FIELDS : self::RELEVANT_APPRO_FIELDS;

        if (! $model->wasChanged($fields)) {
            return;
        }

        $dateColumn = $model instanceof UvTransaction ? 'transaction_date' : 'date_approvisionnement';
        $current = $this->movementDate($model);
        $original = $model->getRawOriginal($dateColumn);
        $original = $original ? Carbon::parse($original) : null;

        if ($current === null) {
            return;
        }

        $this->service()->rebuildFrom($this->earlierOf($current, $original));
    }

    public function deleted(Model $model): void
    {
        $date = $this->movementDate($model);

        if ($date !== null) {
            $this->service()->rebuildFrom($date);
        }
    }

    public function restored(Model $model): void
    {
        $date = $this->movementDate($model);

        if ($date !== null) {
            $this->service()->rebuildFrom($date);
        }
    }

    protected function movementDate(Model $model): ?Carbon
    {
        if ($model instanceof UvTransaction) {
            return $model->transaction_date;
        }

        if ($model instanceof Approvisionnement) {
            return $model->date_approvisionnement;
        }

        return null;
    }

    protected function hasLaterMovements(Carbon $date): bool
    {
        return UvTransaction::where('transaction_date', '>', $date)->where('status', '!=', 'cancelled')->exists()
            || Approvisionnement::where('date_approvisionnement', '>', $date)->exists();
    }

    protected function earlierOf(Carbon $a, ?Carbon $b): Carbon
    {
        if ($b === null) {
            return $a;
        }

        return $a->lessThan($b) ? $a : $b;
    }

    protected function service(): LedgerCascadeService
    {
        return app(LedgerCascadeService::class);
    }
}
