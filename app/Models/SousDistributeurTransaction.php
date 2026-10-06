<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SousDistributeurTransaction extends Model
{
    use BelongsToTenant;
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'sous_distributeur_transactions';

    protected $fillable = [
        'sous_distributeur_id',
        'agent_id',
        'quantity_uv',
        'amount',
        'prix_unitaire',
        'reference',
        'type',
        'transaction_date',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'prix_unitaire' => 'decimal:2',
        'transaction_date' => 'datetime',
    ];

    // ==================== RELATIONS ====================

    public function sousDistributeur(): BelongsTo
    {
        return $this->belongsTo(SousDistributeur::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    // ==================== SCOPES ====================

    public function scopeDistributions($query)
    {
        return $query->where('type', 'distribution');
    }

    public function scopeRetours($query)
    {
        return $query->where('type', 'retour');
    }

    public function scopeForPeriod($query, $startDate, $endDate)
    {
        return $query->whereBetween('transaction_date', [$startDate, $endDate]);
    }

    // ==================== BOOT ====================

    protected static function booted(): void
    {
        static::created(function (SousDistributeurTransaction $transaction) {
            $transaction->sousDistributeur->recalculateSolde();
        });

        static::updated(function (SousDistributeurTransaction $transaction) {
            $transaction->sousDistributeur->recalculateSolde();
        });

        static::deleted(function (SousDistributeurTransaction $transaction) {
            $transaction->sousDistributeur->recalculateSolde();
        });
    }

    // ==================== METHODS ====================

    public static function generateReference(): string
    {
        return 'SDT-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -6));
    }

    // ==================== ACTIVITY LOG ====================

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['sous_distributeur_id', 'quantity_uv', 'amount', 'type'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Transaction sous-distributeur {$eventName}");
    }
}
