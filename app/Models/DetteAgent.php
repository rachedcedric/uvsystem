<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetteAgent extends Model
{
    protected $table = 'dettes_agents';

    protected $fillable = [
        'agent_partenaire_id',
        'total_achats',
        'total_rembourse',
        'dette_restante',
    ];

    protected $casts = [
        'total_achats' => 'decimal:2',
        'total_rembourse' => 'decimal:2',
        'dette_restante' => 'decimal:2',
    ];

    // Relations
    public function agentPartenaire(): BelongsTo
    {
        return $this->belongsTo(AgentPartenaire::class);
    }
}
