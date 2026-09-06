<?php

namespace App\Filament\Resources\SousDistributeurResource\Pages;

use App\Filament\Resources\SousDistributeurResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSousDistributeur extends EditRecord
{
    protected static string $resource = SousDistributeurResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
}
