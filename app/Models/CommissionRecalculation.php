<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal d'audit d'un recalcul de commission journalière : conserve la
 * date concernée, le nombre et montant des ventes prises en compte,
 * l'ancienne et la nouvelle valeur de commission, ainsi que la source et
 * l'auteur du recalcul. Permet de vérifier quelles journées ont été
 * corrigées et pourquoi.
 */
class CommissionRecalculation extends Model
{
    protected $fillable = [
        'date_commission',
        'commission_id',
        'nombre_ventes',
        'montant_total_ventes',
        'ancienne_commission',
        'nouvelle_commission',
        'statut',
        'source',
        'user_id',
        'message',
    ];

    protected $casts = [
        'date_commission' => 'date',
        'nombre_ventes' => 'integer',
        'montant_total_ventes' => 'decimal:2',
        'ancienne_commission' => 'decimal:2',
        'nouvelle_commission' => 'decimal:2',
    ];

    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
