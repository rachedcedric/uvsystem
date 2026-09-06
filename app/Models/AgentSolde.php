<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentSolde extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_id',
        'total_uv_distribues',
        'total_rembourse',
        'solde_restant',
    ];

    protected $casts = [
        'total_uv_distribues' => 'decimal:2',
        'total_rembourse' => 'decimal:2',
        'solde_restant' => 'decimal:2',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Recalcule le solde d'un agent à partir de la même source unique que
     * Agent::recalculateDebt()/debtAsOf() (Agent::soldeTotals()) — jamais
     * une formule locale, pour ne pas revenir à un solde qui diverge de la
     * dette réelle de l'agent.
     */
    public static function recalculate(Agent $agent): self
    {
        $totaux = $agent->soldeTotals();

        return self::updateOrCreate(
            ['agent_id' => $agent->id],
            [
                'total_uv_distribues' => $totaux['distribue'],
                'total_rembourse' => $totaux['rembourse'],
                'solde_restant' => $totaux['distribue'] - $totaux['retourne'] - $totaux['rembourse'],
            ]
        );
    }
}
