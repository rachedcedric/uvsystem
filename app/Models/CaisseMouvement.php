<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Mouvement manuel de la caisse ("Ma caisse") : entrée ou sortie de
 * liquidité saisie à la main par l'utilisateur, avec un libellé. Le solde
 * n'est jamais mis en cache : toujours recalculé en direct depuis la somme
 * des mouvements (voir CaisseService), pour ne jamais désynchroniser deux
 * formules — même principe que Agent::soldeTotals()/FournisseurDebtService.
 */
class CaisseMouvement extends Model
{
    use LogsActivity, SoftDeletes;

    public const TYPE_ENTREE = 'entree';

    public const TYPE_SORTIE = 'sortie';

    protected $fillable = [
        'user_id',
        'type',
        'caisse_categorie_id',
        'amount',
        'libelle',
        'notes',
        'reference',
        'date_mouvement',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date_mouvement' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(CaisseCategorie::class, 'caisse_categorie_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $mouvement) {
            if (! $mouvement->reference) {
                $mouvement->reference = app(\App\Services\ShortReferenceGenerator::class)->generate('C', 'caisse_mouvements');
            }
            if (! $mouvement->date_mouvement) {
                $mouvement->date_mouvement = now();
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Mouvement de caisse {$eventName}: {$this->reference}")
            ->useLogName('caisse');
    }
}
