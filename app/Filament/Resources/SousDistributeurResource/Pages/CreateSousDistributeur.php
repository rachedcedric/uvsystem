<?php

namespace App\Filament\Resources\SousDistributeurResource\Pages;

use App\Filament\Resources\SousDistributeurResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSousDistributeur extends CreateRecord
{
    protected static string $resource = SousDistributeurResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        $this->record->createSoldeIfNotExists();
    }
}
