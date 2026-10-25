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
 * Mouvement manuel de la caisse ("Ma caisse") : entrée ou sortie de
 * liquidité saisie à la main par l'utilisateur, avec un libellé. Le solde
 * n'est jamais mis en cache : toujours recalculé en direct depuis la somme
 * des mouvements (voir CaisseService), pour ne jamais désynchroniser deux
 * formules — même principe que Agent::soldeTotals()/FournisseurDebtService.
 */
class CaisseMouvement extends Model
{
    use BelongsToTenant;
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

    /** Annulation : renseignée uniquement par CaisseService::annulerJournee(), jamais par un formulaire. */
    protected $casts = [
        'amount' => 'decimal:2',
        'date_mouvement' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** Nom du scope global qui écarte les mouvements annulés de tous les soldes, totaux, listes et exports. */
    public const SCOPE_ACTIFS = 'actifs';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Mouvements annulés uniquement (consultation de l'historique des annulations). */
    public function scopeAnnules(Builder $query): Builder
    {
        return $query->withoutGlobalScope(self::SCOPE_ACTIFS)->whereNotNull($query->qualifyColumn('cancelled_at'));
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(CaisseCategorie::class, 'caisse_categorie_id');
    }

    protected static function boot()
    {
        parent::boot();

        // Un mouvement annulé ne compte plus nulle part (solde, totaux, catégories, listes, exports) ; il reste
        // consultable via annules() et le journal d'activité.
        static::addGlobalScope(self::SCOPE_ACTIFS, fn (Builder $query) => $query->whereNull($query->qualifyColumn('cancelled_at')));

        static::creating(function (self $mouvement) {
            if (! $mouvement->reference) {
                $mouvement->reference = app(ShortReferenceGenerator::class)->generate('C', 'caisse_mouvements');
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
