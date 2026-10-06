<?php

namespace App\Filament\Platform\Resources\SubscriptionPlanResource\Pages;

use App\Filament\Platform\Resources\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSubscriptionPlan extends EditRecord
{
    protected static string $resource = SubscriptionPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->visible(fn (SubscriptionPlan $record) => ! $record->isUsed()),
        ];
    }
}
