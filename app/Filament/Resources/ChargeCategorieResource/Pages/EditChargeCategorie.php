<?php

namespace App\Filament\Resources\ChargeCategorieResource\Pages;

use App\Filament\Resources\ChargeCategorieResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditChargeCategorie extends EditRecord
{
    protected static string $resource = ChargeCategorieResource::class;

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
