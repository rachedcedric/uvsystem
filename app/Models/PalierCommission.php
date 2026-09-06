<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PalierCommission extends Model
{
    protected $table = 'paliers_commission';

    protected $fillable = [
        'seuil_min',
        'seuil_max',
        'commission',
        'is_active',
    ];

    protected $casts = [
        'seuil_min' => 'decimal:2',
        'seuil_max' => 'decimal:2',
        'commission' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Trouver le palier correspondant à un montant
     */
    public static function findPalierForMontant(float $montant): ?self
    {
        return self::where('is_active', true)
            ->where('seuil_min', '<=', $montant)
            ->where('seuil_max', '>=', $montant)
            ->first();
    }
}
