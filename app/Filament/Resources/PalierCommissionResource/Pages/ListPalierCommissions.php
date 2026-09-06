<?php

namespace App\Filament\Resources\PalierCommissionResource\Pages;

use App\Filament\Resources\PalierCommissionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPalierCommissions extends ListRecords
{
    protected static string $resource = PalierCommissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nouveau palier'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        return '💡 La commission est calculée sur le TOTAL des ventes UV de la journée (jusqu\'à 18h)';
    }
}
