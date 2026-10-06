<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SousDistributeurRemboursement extends Model
{
    use BelongsToTenant;
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'sous_distributeur_remboursements';

    protected $fillable = [
        'sous_distributeur_id',
        'agent_id',
        'amount',
        'payment_method',
        'reference',
        'payment_date',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'datetime',
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

    public function scopeForPeriod($query, $startDate, $endDate)
    {
        return $query->whereBetween('payment_date', [$startDate, $endDate]);
    }

    // ==================== BOOT ====================

    protected static function booted(): void
    {
        static::created(function (SousDistributeurRemboursement $remboursement) {
            $remboursement->sousDistributeur->recalculateSolde();
        });

        static::updated(function (SousDistributeurRemboursement $remboursement) {
            $remboursement->sousDistributeur->recalculateSolde();
        });

        static::deleted(function (SousDistributeurRemboursement $remboursement) {
            $remboursement->sousDistributeur->recalculateSolde();
        });
    }

    // ==================== METHODS ====================

    public static function generateReference(): string
    {
        return 'SDR-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -6));
    }

    // ==================== ACTIVITY LOG ====================

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['sous_distributeur_id', 'amount', 'payment_method'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Remboursement sous-distributeur {$eventName}");
    }
}
