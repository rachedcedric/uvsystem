<?php

namespace App\Filament\Resources\ApprovisionnementResource\Pages;

use App\Filament\Resources\ApprovisionnementResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListApprovisionnements extends ListRecords
{
    protected static string $resource = ApprovisionnementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nouveau Approvisionnement')
                ->icon('heroicon-o-plus-circle'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ApprovisionnementResource\Widgets\ApprovisionnementStatsWidget::class,
        ];
    }
}
