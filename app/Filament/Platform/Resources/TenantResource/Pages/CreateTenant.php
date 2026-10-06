<?php

namespace App\Filament\Platform\Resources\TenantResource\Pages;

use App\Enums\SubscriptionCycle;
use App\Filament\Platform\Resources\TenantResource;
use App\Models\SubscriptionPlan;
use App\Services\Subscriptions\SubscriptionManager;
use App\Tenancy\TenantProvisioner;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /** Passe par le provisionneur : le tenant naît avec ses données de référence et son admin. */
    protected function handleRecordCreation(array $data): Model
    {
        $admin = [
            'name' => $data['admin_name'],
            'email' => $data['admin_email'],
            'password' => $data['admin_password'],
        ];
        $plan = SubscriptionPlan::findOrFail($data['subscription_plan_id']);
        $cycle = SubscriptionCycle::from($data['subscription_cycle']);
        $mode = $data['subscription_mode'];
        unset($data['admin_name'], $data['admin_email'], $data['admin_password'], $data['subscription_plan_id'], $data['subscription_cycle'], $data['subscription_mode']);

        return DB::transaction(function () use ($data, $admin, $plan, $cycle, $mode) {
            $tenant = app(TenantProvisioner::class)->provision($data, $admin);
            $manager = app(SubscriptionManager::class);

            $mode === 'trial' ? $manager->startTrial($tenant, $plan, $cycle) : $manager->activate($tenant, $plan, $cycle);

            return $tenant;
        });
    }
}
