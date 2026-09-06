<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SousDistributeur extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'sous_distributeurs';

    protected $fillable = [
        'agent_id',
        'nom',
        'telephone',
        'email',
        'adresse',
        'ville',
        'type',
        'plafond_dette',
        'commission_rate',
        'statut',
        'notes',
    ];

    protected $casts = [
        'plafond_dette' => 'decimal:2',
        'commission_rate' => 'decimal:2',
    ];

    // ==================== RELATIONS ====================

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function solde(): HasOne
    {
        return $this->hasOne(SousDistributeurSolde::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SousDistributeurTransaction::class);
    }

    public function remboursements(): HasMany
    {
        return $this->hasMany(SousDistributeurRemboursement::class);
    }

    // ==================== SCOPES ====================

    public function scopeActif($query)
    {
        return $query->where('statut', 'actif');
    }

    public function scopeByAgent($query, int $agentId)
    {
        return $query->where('agent_id', $agentId);
    }

    public function scopeWithDebt($query)
    {
        return $query->whereHas('solde', function ($q) {
            $q->where('solde_restant', '>', 0);
        });
    }

    // ==================== ACCESSORS ====================

    public function getDetteAttribute(): float
    {
        return $this->solde?->solde_restant ?? 0;
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->statut === 'actif';
    }

    public function getDebtRatioAttribute(): float
    {
        if (! $this->plafond_dette || $this->plafond_dette <= 0) {
            return 0;
        }

        return min(100, ($this->dette / $this->plafond_dette) * 100);
    }

    public function getCanReceiveUvAttribute(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if (! $this->plafond_dette) {
            return true; // Pas de plafond = pas de limite
        }

        return $this->dette < $this->plafond_dette;
    }

    public function getAvailableCreditAttribute(): float
    {
        if (! $this->plafond_dette) {
            return PHP_FLOAT_MAX;
        }

        return max(0, $this->plafond_dette - $this->dette);
    }

    // ==================== METHODS ====================

    public function createSoldeIfNotExists(): SousDistributeurSolde
    {
        if (! $this->solde) {
            return $this->solde()->create([
                'total_uv_distribues' => 0,
                'total_rembourse' => 0,
                'solde_restant' => 0,
            ]);
        }

        return $this->solde;
    }

    public function recalculateSolde(): void
    {
        $solde = $this->createSoldeIfNotExists();

        $totalDistribue = $this->transactions()
            ->where('type', 'distribution')
            ->sum('amount');

        $totalRetour = $this->transactions()
            ->where('type', 'retour')
            ->sum('amount');

        $totalRembourse = $this->remboursements()->sum('amount');

        $solde->update([
            'total_uv_distribues' => $totalDistribue - $totalRetour,
            'total_rembourse' => $totalRembourse,
            'solde_restant' => ($totalDistribue - $totalRetour) - $totalRembourse,
        ]);
    }

    // ==================== ACTIVITY LOG ====================

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nom', 'telephone', 'type', 'statut', 'plafond_dette'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Sous-distributeur {$eventName}");
    }
}
