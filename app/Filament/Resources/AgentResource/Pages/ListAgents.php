<?php

namespace App\Filament\Resources\AgentResource\Pages;

use App\Exports\AgentsExport;
use App\Filament\Resources\AgentResource;
use App\Models\Agent;
use App\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class ListAgents extends ListRecords
{
    protected static string $resource = AgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Ajouter un agent')
                ->icon('heroicon-o-plus'),

            Actions\ActionGroup::make([
                Actions\Action::make('exportExcel')
                    ->label('Exporter Excel')
                    ->icon('heroicon-o-table-cells')
                    ->color('success')
                    ->form([
                        Select::make('status')
                            ->label('Filtrer par statut')
                            ->options([
                                '' => 'Tous les agents',
                                'active' => 'Actifs uniquement',
                                'inactive' => 'Inactifs uniquement',
                                'suspended' => 'Suspendus uniquement',
                                'blocked' => 'Bloqués uniquement',
                            ])
                            ->default(''),
                    ])
                    ->action(function (array $data) {
                        $status = $data['status'] ?: null;

                        $filename = 'agents_'.now()->format('Y-m-d_His').'.xlsx';
                        $path = TenantContext::storagePath('exports/'.$filename);

                        Excel::store(new AgentsExport($status), $path, 'local');

                        return redirect()->route('exports.download', ['filename' => $filename]);
                    }),

                Actions\Action::make('exportPdf')
                    ->label('Exporter PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('danger')
                    ->form([
                        Select::make('status')
                            ->label('Filtrer par statut')
                            ->options([
                                '' => 'Tous les agents',
                                'active' => 'Actifs uniquement',
                                'inactive' => 'Inactifs uniquement',
                                'suspended' => 'Suspendus uniquement',
                                'blocked' => 'Bloqués uniquement',
                            ])
                            ->default(''),
                    ])
                    ->action(function (array $data) {
                        $query = Agent::query()->with(['transactions', 'remboursements']);

                        if (! empty($data['status'])) {
                            $query->where('status', $data['status']);
                        }
                        $agents = $query->orderBy('name')->get();

                        $pdf = Pdf::loadView('exports.agents-liste-pdf', [
                            'agents' => $agents,
                            'filters' => [
                                'status' => $data['status'] ?: 'Tous',
                            ],
                            'generatedAt' => now(),
                            'generatedBy' => Auth::user()?->name ?? 'Système',
                        ])->setPaper('a4', 'landscape');

                        return response()->streamDownload(
                            fn () => print ($pdf->output()),
                            'liste_agents_'.now()->format('Y-m-d_His').'.pdf'
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
