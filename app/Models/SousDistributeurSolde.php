<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SousDistributeurSolde extends Model
{
    use BelongsToTenant;

    /** Écriture permise même en accès restreint : voir BelongsToTenant::tenantWritesAlwaysAllowed(). */
    protected static function tenantWritesAlwaysAllowed(): bool
    {
        return true;
    }

    protected $table = 'sous_distributeur_soldes';

    protected $fillable = [
        'sous_distributeur_id',
        'total_uv_distribues',
        'total_rembourse',
        'solde_restant',
    ];

    protected $casts = [
        'total_uv_distribues' => 'decimal:2',
        'total_rembourse' => 'decimal:2',
        'solde_restant' => 'decimal:2',
    ];

    public function sousDistributeur(): BelongsTo
    {
        return $this->belongsTo(SousDistributeur::class);
    }
}
