<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MasterSolde extends Model
{
    use BelongsToTenant;
    use LogsActivity;

    /** Écriture permise même en accès restreint : voir BelongsToTenant::tenantWritesAlwaysAllowed(). */
    protected static function tenantWritesAlwaysAllowed(): bool
    {
        return true;
    }

    protected $fillable = [
        'user_id',
        'total_approvisionne',
        'total_distribue',
        'solde_disponible',
    ];

    protected $casts = [
        'total_approvisionne' => 'decimal:2',
        'total_distribue' => 'decimal:2',
        'solde_disponible' => 'decimal:2',
    ];

    // Relations
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Activity Log
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Solde Master {$eventName}");
    }
}
