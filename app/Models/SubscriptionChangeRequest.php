<?php

namespace App\Models;

use App\Enums\SubscriptionCycle;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Demande de changement d'offre d'un tenant (appliquée à la confirmation du paiement, ou de suite si rien à payer). */
class SubscriptionChangeRequest extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['tenant_subscription_id', 'requested_plan_id', 'requested_cycle', 'status', 'requested_by', 'resolved_by', 'resolved_at', 'quote', 'note'];

    protected function casts(): array
    {
        return ['requested_cycle' => SubscriptionCycle::class, 'quote' => 'array', 'resolved_at' => 'datetime'];
    }

    protected static function tenantWritesAlwaysAllowed(): bool
    {
        return true; // une demande de changement doit rester possible quand l'abonnement est expiré (c'est le moyen de le rétablir)
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'requested_plan_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }
}
