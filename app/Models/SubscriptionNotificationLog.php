<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Journal anti-doublon des notifications d'abonnement (clé unique par type + abonnement + échéance). */
class SubscriptionNotificationLog extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_subscription_id', 'type', 'dedup_key', 'notification', 'payload', 'attempts', 'claimed_at', 'failed_at', 'last_error', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'claimed_at' => 'datetime', 'failed_at' => 'datetime', 'payload' => 'array', 'attempts' => 'integer'];
    }

    protected static function tenantWritesAlwaysAllowed(): bool
    {
        return true;
    }
}
