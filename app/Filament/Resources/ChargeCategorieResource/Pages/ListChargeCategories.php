<?php

namespace App\Filament\Resources\ChargeCategorieResource\Pages;

use App\Filament\Resources\ChargeCategorieResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListChargeCategories extends ListRecords
{
    protected static string $resource = ChargeCategorieResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nouvelle catégorie'),
        ];
    }
}
