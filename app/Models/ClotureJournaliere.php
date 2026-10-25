<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Clôture quotidienne des soldes d'une entreprise : photographie, à 23:59:59 (Africa/Abidjan), des agents et
 * fournisseurs dont le solde est non nul (lignes ClotureSolde). Une seule par entreprise et par date ; jamais
 * recalculée (voir ClotureSoldesService).
 */
class ClotureJournaliere extends Model
{
    use BelongsToTenant;

    public const SOURCE_AUTOMATIQUE = 'automatique';

    public const SOURCE_RATTRAPAGE = 'rattrapage';

    protected $table = 'clotures_journalieres';

    protected $fillable = [
        'date',
        'closed_at',
        'source',
        'nb_dettes',
        'nb_credits',
        'total_dettes',
        'total_credits',
        'verifiee_at',
        'ecart_constate_at',
        'ecart_details',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'verifiee_at' => 'datetime',
        'ecart_constate_at' => 'datetime',
        'ecart_details' => 'array',
        'nb_dettes' => 'integer',
        'nb_credits' => 'integer',
        'total_dettes' => 'decimal:2',
        'total_credits' => 'decimal:2',
    ];

    /** Écriture système (cron), permise même en accès restreint : l'historique ne doit pas présenter de trou. */
    protected static function tenantWritesAlwaysAllowed(): bool
    {
        return true;
    }

    public function soldes(): HasMany
    {
        return $this->hasMany(ClotureSolde::class, 'cloture_id');
    }

    /** Journée clôturée (la colonne `date` reste une chaîne AAAA-MM-JJ, identique sous MySQL et SQLite). */
    public function jour(): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', substr((string) $this->date, 0, 10), config('app.timezone'));
    }

    public function hasEcart(): bool
    {
        return $this->ecart_constate_at !== null && ! empty($this->ecart_details);
    }
}
