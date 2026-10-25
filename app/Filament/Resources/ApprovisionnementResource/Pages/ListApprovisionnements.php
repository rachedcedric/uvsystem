<?php

namespace App\Filament\Resources\ApprovisionnementResource\Pages;

use App\Filament\Actions\ExportPeriodAction;
use App\Filament\Pages\ReportsPage;
use App\Filament\Resources\ApprovisionnementResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListApprovisionnements extends ListRecords
{
    protected static string $resource = ApprovisionnementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('retoursAgents')->label('Retours agents')->icon('heroicon-o-arrows-right-left')->color('gray')
                ->url(ReportsPage::getUrl(['c' => 'retours_agents']))
                ->visible(fn () => ReportsPage::canAccess()),
            ExportPeriodAction::make('approvisionnements'),
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
