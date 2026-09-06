<?php

namespace App\Filament\Resources\RemboursementResource\Pages;

use App\Filament\Resources\RemboursementResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRemboursement extends EditRecord
{
    protected static string $resource = RemboursementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
