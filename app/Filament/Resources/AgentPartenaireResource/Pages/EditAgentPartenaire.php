<?php

namespace App\Filament\Resources\AgentPartenaireResource\Pages;

use App\Filament\Resources\AgentPartenaireResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAgentPartenaire extends EditRecord
{
    protected static string $resource = AgentPartenaireResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
