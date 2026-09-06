<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Approvisionnement extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'user_id',
        'fournisseur_id',
        'amount',
        'reference',
        'date_approvisionnement',
        'notes',
        'solde_uv_avant',
        'solde_uv_apres',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'solde_uv_avant' => 'decimal:2',
        'solde_uv_apres' => 'decimal:2',
        'date_approvisionnement' => 'date',
    ];

    // Relations
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class);
    }

    // Auto-generate reference
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($approvisionnement) {
            if (! $approvisionnement->reference) {
                $approvisionnement->reference = 'APP-'.strtoupper(uniqid());
            }
            if (! $approvisionnement->date_approvisionnement) {
                $approvisionnement->date_approvisionnement = now();
            }
        });
    }

    // Activity Log
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Approvisionnement {$eventName}");
    }
}
