<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paiement d'un abonnement. `confirmed` ne s'obtient QUE par confirmation réelle (prestataire ou
 * attestation d'un super admin avec référence) : voir SubscriptionPaymentGateway.
 */
class SubscriptionPayment extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_subscription_id', 'amount', 'currency', 'status', 'gateway', 'provider_reference',
        'confirmed_at', 'confirmed_by', 'failure_reason', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'confirmed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function tenantWritesAlwaysAllowed(): bool
    {
        return true;
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }
}
