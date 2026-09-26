<?php

namespace App\Filament\Resources\CaisseCategorieResource\Pages;

use App\Filament\Pages\MaCaissePage;
use App\Filament\Resources\CaisseCategorieResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageCaisseCategories extends ManageRecords
{
    protected static string $resource = CaisseCategorieResource::class;

    protected static ?string $title = 'Gérer les catégories de caisse';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Ajouter une catégorie')->createAnother(false),
            Actions\Action::make('back')->label('Retour à Ma Caisse')->color('gray')
                ->icon('heroicon-o-arrow-left')->url(MaCaissePage::getUrl()),
        ];
    }
}
