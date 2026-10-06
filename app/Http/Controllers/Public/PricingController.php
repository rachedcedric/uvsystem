<?php

namespace App\Http\Controllers\Public;

use App\Enums\SubscriptionCycle;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Services\Subscriptions\FeatureCatalog;
use Illuminate\Http\Request;

/** Page publique /offres : uniquement les offres ACTIVES, prix et limites lus en base (jamais en dur). */
class PricingController extends Controller
{
    public function __invoke(Request $request)
    {
        $cycle = SubscriptionCycle::tryFrom((string) $request->query('cycle')) ?? SubscriptionCycle::Monthly;

        $plans = SubscriptionPlan::active()->ordered()->get()->map(fn (SubscriptionPlan $plan) => [
            'plan' => $plan,
            'price' => $plan->priceFor($cycle),
            'features' => collect($plan->resolvedFeatures())->map(fn (string $f) => FeatureCatalog::label($f))->all(),
            'users' => $plan->max_users === null ? 'Utilisateurs illimités' : $plan->max_users.' utilisateurs',
            'agents' => $plan->max_agents === null ? 'Agents illimités' : $plan->max_agents.' agents',
        ]);

        return view('public.offres', ['plans' => $plans, 'cycle' => $cycle]);
    }
}
