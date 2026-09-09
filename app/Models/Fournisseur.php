<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Fournisseur extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'contact',
        'email',
        'address',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    // Relations
    public function approvisionnements(): HasMany
    {
        return $this->hasMany(Approvisionnement::class);
    }

    public function remboursementsFournisseurs(): HasMany
    {
        return $this->hasMany(RemboursementFournisseur::class);
    }

    /**
     * Fournisseurs qui approvisionnent à crédit (à rembourser), à la
     * différence d'un fournisseur "Banque" payé comptant. Base unique
     * utilisée par FournisseurDebtService et DettesFournisseursPage.
     */
    public function scopeCreditFournisseurs($query)
    {
        return $query->where('type', 'AGENT_PREMIUM');
    }

    // Activity Log
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Fournisseur {$eventName}")
            ->useLogName('fournisseurs')
            ->logExcept(['updated_at']);
    }
}
