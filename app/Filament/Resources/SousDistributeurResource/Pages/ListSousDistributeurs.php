<?php

namespace App\Filament\Resources\SousDistributeurResource\Pages;

use App\Filament\Resources\SousDistributeurResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSousDistributeurs extends ListRecords
{
    protected static string $resource = SousDistributeurResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nouveau sous-distributeur'),
        ];
    }
}
