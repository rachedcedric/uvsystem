<?php

namespace App\Filament\Resources\SousDistributeurResource\Pages;

use App\Filament\Resources\SousDistributeurResource;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewSousDistributeur extends ViewRecord
{
    protected static string $resource = SousDistributeurResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Informations')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('agent.name')
                            ->label('Agent parent'),
                        TextEntry::make('telephone'),
                        TextEntry::make('email'),
                        TextEntry::make('adresse'),
                        TextEntry::make('ville'),
                        TextEntry::make('type')
                            ->badge(),
                        TextEntry::make('statut')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'actif' => 'success',
                                'suspendu' => 'warning',
                                'inactif' => 'danger',
                                default => 'gray',
                            }),
                    ])
                    ->columns(3),

                Section::make('Situation financiere')
                    ->schema([
                        TextEntry::make('solde.total_uv_distribues')
                            ->label('Total distribue')
                            ->money('XOF'),
                        TextEntry::make('solde.total_rembourse')
                            ->label('Total rembourse')
                            ->money('XOF'),
                        TextEntry::make('solde.solde_restant')
                            ->label('Dette actuelle')
                            ->money('XOF')
                            ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                        TextEntry::make('plafond_dette')
                            ->label('Plafond de dette')
                            ->money('XOF'),
                        TextEntry::make('commission_rate')
                            ->label('Commission')
                            ->suffix('%'),
                    ])
                    ->columns(5),
            ]);
    }
}
