<?php

namespace App\Filament\Agent\Pages;

use App\Models\Agent;
use App\Models\Remboursement;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class MyRemboursements extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $navigationLabel = 'Mes remboursements';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.agent.pages.my-remboursements';

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
                Remboursement::query()
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
                    ->sortable()
                    ->color('success'),

                TextColumn::make('payment_method')
                    ->label('Méthode')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'cash' => 'Espèces',
                        'mobile_money' => 'Mobile Money',
                        'bank_transfer' => 'Virement',
                        'cheque' => 'Chèque',
                        default => $state ?? 'Espèces',
                    }),

                TextColumn::make('reference')
                    ->label('Référence')
                    ->default('-'),

                TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(30)
                    ->default('-'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
