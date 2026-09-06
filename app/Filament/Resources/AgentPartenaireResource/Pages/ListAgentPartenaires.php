<?php

namespace App\Filament\Resources\AgentPartenaireResource\Pages;

use App\Filament\Resources\AgentPartenaireResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAgentPartenaires extends ListRecords
{
    protected static string $resource = AgentPartenaireResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
