<?php

namespace App\Filament\Platform\Resources\TenantSubscriptionResource\Pages;

use App\Filament\Platform\Resources\TenantSubscriptionResource;
use Filament\Resources\Pages\ViewRecord;

/** Consultation (détail + historique du tenant) ; les opérations se font depuis la liste, via le manager. */
class ViewTenantSubscription extends ViewRecord
{
    protected static string $resource = TenantSubscriptionResource::class;
}
