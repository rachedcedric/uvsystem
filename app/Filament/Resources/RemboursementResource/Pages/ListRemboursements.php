<?php

namespace App\Filament\Resources\RemboursementResource\Pages;

use App\Exports\RemboursementsExport;
use App\Filament\Resources\RemboursementResource;
use App\Filament\Widgets\RemboursementsStatsWidget;
use App\Models\Agent;
use App\Models\PaymentMethod;
use App\Models\Remboursement;
use App\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class ListRemboursements extends ListRecords
{
    protected static string $resource = RemboursementResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            RemboursementsStatsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nouveau Remboursement')
                ->icon('heroicon-o-plus-circle'),

            Actions\ActionGroup::make([
                Actions\Action::make('exportExcel')
                    ->label('Exporter Excel')
                    ->icon('heroicon-o-table-cells')
                    ->color('success')
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

                                Forms\Components\Select::make('payment_method')
                                    ->label('Mode de paiement')
                                    ->options(fn () => array_merge(['' => 'Tous'], PaymentMethod::getActiveOptions()))
                                    ->default(''),
                            ])
                            ->columns(2),
                    ])
                    ->action(function (array $data) {
                        $filename = 'remboursements_'.now()->format('Y-m-d_His').'.xlsx';
                        $path = TenantContext::storagePath('exports/'.$filename);

                        Excel::store(
                            new RemboursementsExport(
                                $data['agent_id'] ?? null,
                                $data['start_date'] ?? null,
                                $data['end_date'] ?? null,
                                $data['payment_method'] ?: null
                            ),
                            $path,
                            'local'
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

                                Forms\Components\Select::make('payment_method')
                                    ->label('Mode de paiement')
                                    ->options(fn () => array_merge(['' => 'Tous'], PaymentMethod::getActiveOptions()))
                                    ->default(''),
                            ])
                            ->columns(2),
                    ])
                    ->action(function (array $data) {
                        $query = Remboursement::query()->with(['agent']);

                        if (! empty($data['agent_id'])) {
                            $query->where('agent_id', $data['agent_id']);
                        }
                        if (! empty($data['start_date'])) {
                            $query->whereDate('payment_date', '>=', $data['start_date']);
                        }
                        if (! empty($data['end_date'])) {
                            $query->whereDate('payment_date', '<=', $data['end_date']);
                        }
                        if (! empty($data['payment_method'])) {
                            $query->where('payment_method', $data['payment_method']);
                        }

                        $remboursements = $query->orderBy('payment_date', 'desc')->get();

                        $pdf = Pdf::loadView('exports.remboursements-pdf', [
                            'remboursements' => $remboursements,
                            'filters' => [
                                'agent' => $data['agent_id'] ? Agent::find($data['agent_id'])?->name : 'Tous',
                                'start_date' => $data['start_date'] ?? 'Début',
                                'end_date' => $data['end_date'] ?? now()->format('d/m/Y'),
                                'payment_method' => $data['payment_method'] ?: 'Tous',
                            ],
                            'generatedAt' => now(),
                            'generatedBy' => Auth::user()?->name ?? 'Système',
                        ])->setPaper('a4', 'landscape');

                        return response()->streamDownload(
                            fn () => print ($pdf->output()),
                            'remboursements_'.now()->format('Y-m-d_His').'.pdf'
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
