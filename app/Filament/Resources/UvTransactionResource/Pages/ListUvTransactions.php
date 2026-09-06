<?php

namespace App\Filament\Resources\UvTransactionResource\Pages;

use App\Exports\UvTransactionsExport;
use App\Filament\Resources\UvTransactionResource;
use App\Filament\Widgets\VentesUvStatsWidget;
use App\Models\Agent;
use App\Models\UvTransaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class ListUvTransactions extends ListRecords
{
    protected static string $resource = UvTransactionResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            VentesUvStatsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nouvelle Vente UV')
                ->icon('heroicon-o-plus-circle'),

            Actions\ActionGroup::make([
                Actions\Action::make('exportExcel')
                    ->label('Exporter Excel')
                    ->icon('heroicon-o-table-cells')
                    ->color('success')
                    ->form([
                        Forms\Components\Section::make('Filtres d\'export')
                            ->description('Personnalisez votre export de transactions')
                            ->schema([
                                Forms\Components\Select::make('agent_id')
                                    ->label('Agent')
                                    ->options(Agent::where('status', 'active')->pluck('name', 'id'))
                                    ->searchable()
                                    ->placeholder('Tous les agents'),

                                Forms\Components\DatePicker::make('start_date')
                                    ->label('Date de début')
                                    ->native(false)
                                    ->placeholder('Depuis le début'),

                                Forms\Components\DatePicker::make('end_date')
                                    ->label('Date de fin')
                                    ->native(false)
                                    ->default(now())
                                    ->placeholder('Jusqu\'à aujourd\'hui'),

                                Forms\Components\Select::make('transaction_type')
                                    ->label('Type de transaction')
                                    ->options([
                                        '' => 'Tous les types',
                                        'distribution' => 'Distribution',
                                        'retour' => 'Retour',
                                    ])
                                    ->default(''),
                            ])
                            ->columns(2),
                    ])
                    ->action(function (array $data) {
                        $filename = 'ventes_uv_'.now()->format('Y-m-d_His').'.xlsx';
                        $path = 'exports/'.$filename;

                        Excel::store(
                            new UvTransactionsExport(
                                $data['agent_id'] ?? null,
                                $data['start_date'] ?? null,
                                $data['end_date'] ?? null,
                                $data['transaction_type'] ?: null
                            ),
                            $path,
                            'public'
                        );

                        return redirect()->route('exports.download', ['filename' => $filename]);
                    }),

                Actions\Action::make('exportPdf')
                    ->label('Exporter PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('danger')
                    ->form([
                        Forms\Components\Section::make('Filtres d\'export')
                            ->schema([
                                Forms\Components\Select::make('agent_id')
                                    ->label('Agent')
                                    ->options(Agent::where('status', 'active')->pluck('name', 'id'))
                                    ->searchable()
                                    ->placeholder('Tous les agents'),

                                Forms\Components\DatePicker::make('start_date')
                                    ->label('Date de début')
                                    ->native(false),

                                Forms\Components\DatePicker::make('end_date')
                                    ->label('Date de fin')
                                    ->native(false)
                                    ->default(now()),

                                Forms\Components\Select::make('transaction_type')
                                    ->label('Type')
                                    ->options([
                                        '' => 'Tous',
                                        'distribution' => 'Distributions',
                                        'retour' => 'Retours',
                                    ])
                                    ->default(''),
                            ])
                            ->columns(2),
                    ])
                    ->action(function (array $data) {
                        $query = UvTransaction::query()->with(['agent', 'fournisseur']);

                        if (! empty($data['agent_id'])) {
                            $query->where('agent_id', $data['agent_id']);
                        }
                        if (! empty($data['start_date'])) {
                            $query->whereDate('transaction_date', '>=', $data['start_date']);
                        }
                        if (! empty($data['end_date'])) {
                            $query->whereDate('transaction_date', '<=', $data['end_date']);
                        }
                        if (! empty($data['transaction_type'])) {
                            $query->where('transaction_type', $data['transaction_type']);
                        }

                        $transactions = $query->orderBy('transaction_date', 'desc')->get();

                        $pdf = Pdf::loadView('exports.ventes-uv-pdf', [
                            'transactions' => $transactions,
                            'filters' => [
                                'agent' => $data['agent_id'] ? Agent::find($data['agent_id'])?->name : 'Tous',
                                'start_date' => $data['start_date'] ?? 'Début',
                                'end_date' => $data['end_date'] ?? now()->format('d/m/Y'),
                                'type' => $data['transaction_type'] ?: 'Tous',
                            ],
                            'generatedAt' => now(),
                            'generatedBy' => Auth::user()?->name ?? 'Système',
                        ])->setPaper('a4', 'landscape');

                        return response()->streamDownload(
                            fn () => print ($pdf->output()),
                            'ventes_uv_'.now()->format('Y-m-d_His').'.pdf'
                        );
                    }),
            ])
                ->label('Exporter')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->button(),
        ];
    }
}
