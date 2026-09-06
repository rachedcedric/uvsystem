<?php

namespace App\Filament\Resources\ApprovisionnementResource\Pages;

use App\Filament\Resources\ApprovisionnementResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditApprovisionnement extends EditRecord
{
    protected static string $resource = ApprovisionnementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
