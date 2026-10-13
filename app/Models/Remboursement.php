<?php

namespace App\Models;

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

class Remboursement extends Model
{
    use BelongsToTenant;
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'agent_id',
        'user_id',
        'amount',
        'payment_method',
        'reference',
        'payment_date',
        'status',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** Moyen de paiement (la colonne payment_method stocke le code du moyen). */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method', 'code');
    }

    /**
     * Remboursements d'un agent, entre 00:00:00 aujourd'hui et l'instant
     * présent (fuseau horaire de l'application), les plus récents en
     * premier. Miroir de UvTransaction::scopeVentesAgentDuJour().
     */
    public function scopeAgentDuJour(Builder $query, int $agentId): Builder
    {
        return $query
            ->where('agent_id', $agentId)
            ->whereBetween('payment_date', [Carbon::today(), Carbon::now()])
            ->orderByDesc('payment_date')
            ->orderByDesc('id');
    }

    // Auto-generate reference
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($remboursement) {
            if (! $remboursement->reference) {
                $remboursement->reference = app(ShortReferenceGenerator::class)->generate('R', 'remboursements');
            }
            if (! $remboursement->payment_date) {
                $remboursement->payment_date = now();
            }
        });
    }

    // Activity Log configuration
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Remboursement {$eventName}: {$this->reference}")
            ->useLogName('remboursements');
    }
}
