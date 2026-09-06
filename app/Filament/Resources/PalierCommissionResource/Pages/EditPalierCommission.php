<?php

namespace App\Filament\Resources\PalierCommissionResource\Pages;

use App\Filament\Resources\PalierCommissionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPalierCommission extends EditRecord
{
    protected static string $resource = PalierCommissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
