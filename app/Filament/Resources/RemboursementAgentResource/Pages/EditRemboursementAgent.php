<?php

namespace App\Filament\Resources\RemboursementAgentResource\Pages;

use App\Filament\Resources\RemboursementAgentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRemboursementAgent extends EditRecord
{
    protected static string $resource = RemboursementAgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
