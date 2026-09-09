<?php

namespace App\Filament\Resources\RemboursementFournisseurResource\Pages;

use App\Filament\Resources\RemboursementFournisseurResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRemboursementFournisseurs extends ListRecords
{
    protected static string $resource = RemboursementFournisseurResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nouveau Remboursement')
                ->icon('heroicon-o-plus-circle'),
        ];
    }
}
