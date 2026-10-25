<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solde d'un agent ou d'un fournisseur figé à la clôture d'une journée. Nom et type sont copiés : la ligne reste
 * lisible si le compte est renommé ou supprimé ensuite.
 *
 * Nature (point de vue du Master) : « dette » = le Master doit au compte ; « crédit » = le compte doit au Master.
 */
class ClotureSolde extends Model
{
    use BelongsToTenant;

    public const NATURE_DETTE = 'dette';

    public const NATURE_CREDIT = 'credit';

    public const NATURES = [self::NATURE_DETTE => 'Dette', self::NATURE_CREDIT => 'Crédit'];

    public const TYPE_AGENT = 'agent';

    public const TYPE_FOURNISSEUR = 'fournisseur';

    protected $table = 'cloture_soldes';

    protected $fillable = [
        'cloture_id',
        'date',
        'compte_type',
        'compte_id',
        'nom',
        'type_label',
        'solde',
        'nature',
        'montant',
    ];

    protected $casts = [
        'compte_id' => 'integer',
        'solde' => 'decimal:2',
        'montant' => 'decimal:2',
    ];

    protected static function tenantWritesAlwaysAllowed(): bool
    {
        return true;
    }

    public function cloture(): BelongsTo
    {
        return $this->belongsTo(ClotureJournaliere::class, 'cloture_id');
    }

    public function natureLabel(): string
    {
        return self::NATURES[$this->nature] ?? $this->nature;
    }

    /**
     * Nature d'un solde selon la convention du compte : un agent au solde positif doit au Master (crédit) ; un
     * fournisseur au solde positif est dû par le Master (dette), un découvert fournisseur est un crédit.
     */
    public static function natureFor(string $compteType, string $solde): ?string
    {
        $sign = bccomp($solde, '0', 2);
        if ($sign === 0) {
            return null;
        }

        return ($compteType === self::TYPE_AGENT) === ($sign > 0) ? self::NATURE_CREDIT : self::NATURE_DETTE;
    }
}
