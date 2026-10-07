<?php

namespace App\Services\Subscriptions;

use App\Models\Agent;
use App\Models\SubscriptionChangeRequest;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;

class TenantHealthMetrics
{
    /** @return array{quota_reached: int, user_limit_reached: int, agent_limit_reached: int, pending_changes: int, pending_payments: int, without_master: int} */
    public function snapshot(): array
    {
        return TenantContext::withoutTenancy(function (): array {
            $tenants = Tenant::query()->with('currentSubscription.plan')->get();
            $userCounts = User::withoutGlobalScopes()
                ->whereNotNull('tenant_id')
                ->selectRaw('tenant_id, COUNT(*) as aggregate')
                ->groupBy('tenant_id')
                ->pluck('aggregate', 'tenant_id');
            $agentCounts = Agent::withoutGlobalScope(TenantScope::class)
                ->selectRaw('tenant_id, COUNT(*) as aggregate')
                ->groupBy('tenant_id')
                ->pluck('aggregate', 'tenant_id');
            $masterCounts = User::withoutGlobalScopes()
                ->whereNotNull('tenant_id')
                ->whereHas('roles', fn ($query) => $query->where('name', 'master'))
                ->selectRaw('tenant_id, COUNT(*) as aggregate')
                ->groupBy('tenant_id')
                ->pluck('aggregate', 'tenant_id');

            $userLimitReached = 0;
            $agentLimitReached = 0;
            $quotaReached = 0;

            foreach ($tenants as $tenant) {
                $plan = $tenant->currentSubscription?->plan;
                $usersReached = $plan?->max_users !== null
                    && (int) ($userCounts[$tenant->id] ?? 0) >= $plan->max_users;
                $agentsReached = $plan?->max_agents !== null
                    && (int) ($agentCounts[$tenant->id] ?? 0) >= $plan->max_agents;

                $userLimitReached += (int) $usersReached;
                $agentLimitReached += (int) $agentsReached;
                $quotaReached += (int) ($usersReached || $agentsReached);
            }

            return [
                'quota_reached' => $quotaReached,
                'user_limit_reached' => $userLimitReached,
                'agent_limit_reached' => $agentLimitReached,
                'pending_changes' => SubscriptionChangeRequest::withoutGlobalScopes()
                    ->where('status', SubscriptionChangeRequest::PENDING)->count(),
                'pending_payments' => SubscriptionPayment::withoutGlobalScopes()
                    ->where('status', SubscriptionPayment::PENDING)->count(),
                'without_master' => $tenants->filter(fn (Tenant $tenant) => (int) ($masterCounts[$tenant->id] ?? 0) === 0)->count(),
            ];
        });
    }
}
