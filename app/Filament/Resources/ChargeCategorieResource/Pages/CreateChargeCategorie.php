<?php

namespace App\Filament\Resources\ChargeCategorieResource\Pages;

use App\Filament\Resources\ChargeCategorieResource;
use Filament\Resources\Pages\CreateRecord;

class CreateChargeCategorie extends CreateRecord
{
    protected static string $resource = ChargeCategorieResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
