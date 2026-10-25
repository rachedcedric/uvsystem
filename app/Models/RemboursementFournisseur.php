<?php

namespace App\Models;

use App\Services\ShortReferenceGenerator;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Remboursement effectué PAR l'entreprise VERS un fournisseur de type
 * "Agent Premium" (qui approvisionne à crédit, à la différence d'un
 * fournisseur "Banque", payé comptant). Miroir exact de Remboursement
 * (paiement d'un agent) mais côté fournisseur — voir FournisseurDebtService
 * pour le calcul de la dette (source unique, jamais recalculée ailleurs).
 */
class RemboursementFournisseur extends Model
{
    use BelongsToTenant;
    use LogsActivity, SoftDeletes;

    protected $table = 'remboursements_fournisseurs';

    protected $fillable = [
        'fournisseur_id',
        'user_id',
        'amount',
        'payment_method',
        'reference',
        'payment_date',
        'notes',
    ];

    /** Annulation : renseignée uniquement par FournisseurDebtService::cancel(), jamais par un formulaire. */
    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Remboursements qui comptent dans le solde du fournisseur (non annulés). */
    public function scopeActifs(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $remboursement) {
            if (! $remboursement->reference) {
                $remboursement->reference = app(ShortReferenceGenerator::class)->generate('R', 'remboursements_fournisseurs');
            }
            if (! $remboursement->payment_date) {
                $remboursement->payment_date = now();
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Remboursement fournisseur {$eventName}: {$this->reference}")
            ->useLogName('remboursements_fournisseurs');
    }
}
