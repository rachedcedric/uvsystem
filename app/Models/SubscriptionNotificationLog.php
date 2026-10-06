<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Journal anti-doublon des notifications d'abonnement (clé unique par type + abonnement + échéance). */
class SubscriptionNotificationLog extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_subscription_id', 'type', 'dedup_key', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    protected static function tenantWritesAlwaysAllowed(): bool
    {
        return true;
    }
}
