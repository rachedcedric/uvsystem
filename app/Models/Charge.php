<?php

namespace App\Models;

use App\Exceptions\SubscriptionLimitReached;
use App\Services\Subscriptions\SubscriptionEntitlements;
use App\Tenancy\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Charge extends Model
{
    use BelongsToTenant;

    /** Création sérialisée par tenant : le contrôle d'espace de stockage (justificatif) ne peut pas être doublé. */
    protected static function enforcesEntitlements(): bool
    {
        return true;
    }

    /**
     * Espace de stockage de l'offre : le justificatif vient d'être enregistré dans le dossier du tenant ; s'il fait
     * dépasser l'espace autorisé, la charge est refusée ET ce fichier (et lui seul) est retiré. Les fichiers
     * déjà présents ne sont jamais supprimés.
     */
    protected function enforceEntitlementsOnInsert(): void
    {
        if (! filled($this->justificatif_path)) {
            return;
        }

        $disk = Storage::disk('local');

        if ($disk->exists($this->justificatif_path)
            && ($message = app(SubscriptionEntitlements::class)->storageRefusal(0, (int) $this->tenant_id)) !== null) {
            $disk->delete($this->justificatif_path);

            throw SubscriptionLimitReached::because($message, 'justificatif');
        }
    }

    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'category_id',
        'libelle',
        'montant',
        'date_charge',
        'heure_charge',
        'description',
        'justificatif_path',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'date_charge' => 'date',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ChargeCategorie::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Charges dont la date tombe dans [$start, $end] (bornes incluses).
     * Même forme que Commission::scopeForPeriod(). Ne jamais tronquer les
     * bornes en simple date ('Y-m-d') : date_charge est castée 'date', donc
     * stockée en base avec un suffixe horaire ("... 00:00:00") — une borne
     * supérieure tronquée exclurait par comparaison lexicographique la
     * ligne du dernier jour de la période.
     */
    public function scopeForPeriod(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereBetween('date_charge', [$start, $end]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Charge {$eventName}: {$this->libelle}");
    }
}
