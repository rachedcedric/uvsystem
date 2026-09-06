<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class VenteUv extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'ventes_uv';

    protected $fillable = [
        'user_id',
        'agent_partenaire_id',
        'quantity_uv',
        'montant_total',
        'prix_unitaire',
        'reference',
        'date_vente',
        'notes',
    ];

    protected $casts = [
        'quantity_uv' => 'decimal:2',
        'montant_total' => 'decimal:2',
        'prix_unitaire' => 'decimal:2',
        'date_vente' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ventes_uv')
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($vente) {
            if (empty($vente->reference)) {
                $vente->reference = 'VENTE-'.strtoupper(uniqid());
            }
            if (empty($vente->date_vente)) {
                $vente->date_vente = now();
            }
            // Calculer prix unitaire automatiquement
            if ($vente->quantity_uv > 0) {
                $vente->prix_unitaire = $vente->montant_total / $vente->quantity_uv;
            }
        });
    }

    // Relations
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agentPartenaire(): BelongsTo
    {
        return $this->belongsTo(AgentPartenaire::class);
    }
}
