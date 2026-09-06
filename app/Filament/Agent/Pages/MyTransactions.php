<?php

namespace App\Filament\Agent\Pages;

use App\Models\Agent;
use App\Models\UvTransaction;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class MyTransactions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationLabel = 'Mes distributions';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.agent.pages.my-transactions';

    public ?Agent $agent = null;

    public function mount(): void
    {
        $user = Auth::user();
        $this->agent = Agent::where('user_id', $user->id)
            ->orWhere('email', $user->email)
            ->first();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                UvTransaction::query()
                    ->when($this->agent, fn ($q) => $q->where('agent_id', $this->agent->id))
                    ->when(! $this->agent, fn ($q) => $q->whereRaw('1 = 0'))
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Montant')
                    ->money('XOF')
                    ->sortable(),

                TextColumn::make('transaction_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'distribution' => 'success',
                        'retour' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('fournisseur.name')
                    ->label('Fournisseur')
                    ->default('-'),

                TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(30)
                    ->default('-'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('fournisseur_id')
                    ->relationship('fournisseur', 'name')
                    ->label('Fournisseur'),
            ])
            ->paginated([10, 25, 50]);
    }
}
