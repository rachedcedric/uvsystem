<?php

namespace App\Filament\Resources\PalierCommissionResource\Pages;

use App\Filament\Resources\PalierCommissionResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePalierCommission extends CreateRecord
{
    protected static string $resource = PalierCommissionResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
