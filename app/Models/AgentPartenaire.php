<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AgentPartenaire extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'nom_entreprise',
        'numero_telephone',
        'lieu',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    // Activity Log
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Agent Partenaire {$eventName}")
            ->useLogName('agents_partenaires');
    }

    // Relations
    public function ventesUv(): HasMany
    {
        return $this->hasMany(VenteUv::class);
    }

    public function remboursements(): HasMany
    {
        return $this->hasMany(RemboursementAgent::class);
    }

    public function detteAgent(): HasOne
    {
        return $this->hasOne(DetteAgent::class);
    }
}
