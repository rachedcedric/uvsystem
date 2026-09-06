<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RemboursementAgent extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'remboursements_agents';

    protected $fillable = [
        'agent_partenaire_id',
        'montant',
        'moyen_paiement',
        'reference',
        'date_remboursement',
        'notes',
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'date_remboursement' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('remboursements_agents')
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($remboursement) {
            if (empty($remboursement->reference)) {
                $remboursement->reference = 'REMB-'.strtoupper(uniqid());
            }
            if (empty($remboursement->date_remboursement)) {
                $remboursement->date_remboursement = now();
            }
        });
    }

    // Relations
    public function agentPartenaire(): BelongsTo
    {
        return $this->belongsTo(AgentPartenaire::class);
    }
}
