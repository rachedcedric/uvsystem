<?php

namespace App\Filament\Resources\AgentSoldeResource\Pages;

use App\Filament\Resources\AgentSoldeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAgentSolde extends EditRecord
{
    protected static string $resource = AgentSoldeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
