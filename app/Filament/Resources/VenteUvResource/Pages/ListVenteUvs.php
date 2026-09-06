<?php

namespace App\Filament\Resources\VenteUvResource\Pages;

use App\Filament\Resources\VenteUvResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVenteUvs extends ListRecords
{
    protected static string $resource = VenteUvResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
