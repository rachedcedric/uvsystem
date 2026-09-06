<?php

namespace App\Filament\Resources\UvTransactionResource\Pages;

use App\Filament\Resources\UvTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUvTransaction extends EditRecord
{
    protected static string $resource = UvTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
