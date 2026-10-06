<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushToken extends Model
{
    use BelongsToTenant;

    /** Écriture permise même en accès restreint : voir BelongsToTenant::tenantWritesAlwaysAllowed(). */
    protected static function tenantWritesAlwaysAllowed(): bool
    {
        return true;
    }

    protected $fillable = [
        'user_id',
        'token',
        'platform',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
