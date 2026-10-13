<?php

namespace App\Models;

use App\Services\CommissionBusinessDateResolver;
use App\Services\ShortReferenceGenerator;
use App\Tenancy\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class UvTransaction extends Model
{
    use BelongsToTenant;
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'agent_id',
        'fournisseur_id',
        'amount',
        'commission_rate',
        'commission_amount',
        'transaction_type',
        'reference',
        'transaction_date',
        'notes',
        'status',
        'solde_uv_avant',
        'solde_uv_apres',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'solde_uv_avant' => 'decimal:2',
        'solde_uv_apres' => 'decimal:2',
        'transaction_date' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class);
    }

    /**
     * Journée métier de commission (clôture à 18:02) à laquelle appartient
     * cette vente, calculée dynamiquement à partir de sa date de création
     * réelle — jamais stockée, jamais utilisée pour modifier created_at.
     * Source unique de vérité : App\Services\CommissionBusinessDateResolver.
     */
    public function getCommissionBusinessDateAttribute(): Carbon
    {
        return app(CommissionBusinessDateResolver::class)->resolve($this->created_at);
    }

    /**
     * Ventes UV (distributions) d'un agent, entre 00:00:00 aujourd'hui et
     * l'instant présent (fuseau horaire de l'application), les plus
     * récentes en premier. Source unique pour l'historique du jour affiché
     * sur la page de création de vente ET pour l'endpoint API équivalent.
     */
    public function scopeVentesAgentDuJour(Builder $query, int $agentId): Builder
    {
        return $query
            ->where('agent_id', $agentId)
            ->where('transaction_type', 'distribution')
            ->whereBetween('transaction_date', [Carbon::today(), Carbon::now()])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');
    }

    // Auto-generate reference
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            if (! $transaction->reference) {
                $transaction->reference = app(ShortReferenceGenerator::class)->generate('V', 'uv_transactions');
            }
            if (! $transaction->transaction_date) {
                $transaction->transaction_date = now();
            }
        });
    }

    // Activity Log configuration
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Transaction UV {$eventName}: {$this->reference}")
            ->useLogName('transactions');
    }
}
