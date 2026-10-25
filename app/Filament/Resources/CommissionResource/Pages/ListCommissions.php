<?php

namespace App\Filament\Resources\CommissionResource\Pages;

use App\Exports\CommissionsExport;
use App\Filament\Actions\ExportPeriodAction;
use App\Filament\Resources\CommissionResource;
use App\Filament\Widgets\CommissionKpisWidget;
use App\Filament\Widgets\CommissionMonthlyChartWidget;
use App\Models\Agent;
use App\Models\Commission;
use App\Services\CommissionService;
use App\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class ListCommissions extends ListRecords
{
    protected static string $resource = CommissionResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            CommissionKpisWidget::class,
            CommissionMonthlyChartWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportPeriodAction::make('commissions'),
            Actions\Action::make('voirBareme')
                ->label('📊 Barème')
                ->icon('heroicon-o-chart-bar')
                ->color('info')
                ->modalHeading('Barème des Commissions')
                ->modalDescription('Commissions calculées par volume de transactions journalier')
                ->modalContent(view('filament.modals.bareme-commissions'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fermer'),

            Actions\Action::make('calculerMaintenant')
                ->label('Calculer maintenant')
                ->icon('heroicon-o-calculator')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Calculer les commissions')
                ->modalDescription('Cette action va calculer les commissions pour les transactions d\'aujourd\'hui. Continuer ?')
                ->action(function () {
                    try {
                        $service = new CommissionService;
                        $stats = $service->calculerCommissionsDuJour();

                        Notification::make()
                            ->title('Commissions calculées')
                            ->body(sprintf(
                                '%d commission(s) créée(s) pour un total de %s FCFA',
                                $stats['commissions_creees'],
                                number_format($stats['montant_total'], 0, ',', ' ')
                            ))
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Erreur')
                            ->body('Erreur lors du calcul : '.$e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('recalculerCommissions')
                ->label('Recalculer les commissions')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->form([
                    Forms\Components\Section::make('Recalcul des commissions')
                        ->description('Recalcule la commission à partir des ventes réelles (même règle de commission que le calcul automatique). Idempotent : jamais de doublon, les journées déjà correctes ne sont pas modifiées.')
                        ->schema([
                            Forms\Components\Radio::make('mode')
                                ->label('Portée du recalcul')
                                ->options([
                                    'date' => 'Une date précise',
                                    'periode' => 'Une période',
                                    'incomplete' => 'Uniquement les journées incomplètes ('.config('commission.catchup_window_days', 30).' derniers jours)',
                                ])
                                ->default('date')
                                ->live()
                                ->required(),

                            Forms\Components\DatePicker::make('date')
                                ->label('Date à recalculer')
                                ->native(false)
                                ->default(now())
                                ->required()
                                ->visible(fn (Get $get) => $get('mode') === 'date'),

                            Forms\Components\DatePicker::make('start_date')
                                ->label('Du')
                                ->native(false)
                                ->required()
                                ->visible(fn (Get $get) => $get('mode') === 'periode'),

                            Forms\Components\DatePicker::make('end_date')
                                ->label('Au')
                                ->native(false)
                                ->default(now())
                                ->required()
                                ->visible(fn (Get $get) => $get('mode') === 'periode'),
                        ]),
                ])
                ->action(function (array $data) {
                    $service = app(CommissionService::class);

                    try {
                        $resultats = match ($data['mode']) {
                            'date' => [
                                Carbon::parse($data['date'])->format('Y-m-d') => $service->verifierEtCorrigerCommission(
                                    Carbon::parse($data['date']),
                                    'manual'
                                ),
                            ],
                            'periode' => $service->recalculerCommissionsPourPeriode(
                                Carbon::parse($data['start_date']),
                                Carbon::parse($data['end_date']),
                                'manual'
                            ),
                            'incomplete' => $service->rattraperCommissionsManquantes(),
                            default => [],
                        };
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Erreur')
                            ->body('Erreur lors du recalcul : '.$e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $correctes = collect($resultats)->where('statut', 'unchanged')->count();
                    $recalculees = collect($resultats)->whereIn('statut', ['created', 'updated'])->count();
                    $echecs = collect($resultats)->where('statut', 'failed')->count();

                    $notification = Notification::make()
                        ->title('Recalcul terminé')
                        ->body(sprintf(
                            '%d journée(s) vérifiée(s) — %d correcte(s) — %d recalculée(s)%s',
                            count($resultats),
                            $correctes,
                            $recalculees,
                            $echecs > 0 ? " — {$echecs} échec(s)" : ''
                        ));

                    $echecs > 0 ? $notification->warning() : $notification->success();
                    $notification->send();
                }),

            Actions\ActionGroup::make([
                Actions\Action::make('exportExcel')
                    ->label('Exporter Excel')
                    ->icon('heroicon-o-table-cells')
                    ->color('success')
                    ->form([
                        Forms\Components\Section::make('Filtres')
                            ->schema([
                                Forms\Components\Select::make('agent_id')
                                    ->label('Agent')
                                    ->options(Agent::where('status', 'active')->pluck('name', 'id'))
                                    ->searchable()
                                    ->placeholder('Tous'),

                                Forms\Components\DatePicker::make('start_date')
                                    ->label('Du')
                                    ->native(false),

                                Forms\Components\DatePicker::make('end_date')
                                    ->label('Au')
                                    ->native(false)
                                    ->default(now()),
                            ])
                            ->columns(3),
                    ])
                    ->action(function (array $data) {
                        $filename = 'commissions_'.now()->format('Y-m-d_His').'.xlsx';
                        $path = TenantContext::storagePath('exports/'.$filename);

                        Excel::store(
                            new CommissionsExport(
                                $data['agent_id'] ?? null,
                                $data['start_date'] ?? null,
                                $data['end_date'] ?? null
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
                        Forms\Components\Section::make('Filtres')
                            ->schema([
                                Forms\Components\Select::make('agent_id')
                                    ->label('Agent')
                                    ->options(Agent::where('status', 'active')->pluck('name', 'id'))
                                    ->searchable()
                                    ->placeholder('Tous'),

                                Forms\Components\DatePicker::make('start_date')
                                    ->label('Du')
                                    ->native(false),

                                Forms\Components\DatePicker::make('end_date')
                                    ->label('Au')
                                    ->native(false)
                                    ->default(now()),
                            ])
                            ->columns(3),
                    ])
                    ->action(function (array $data) {
                        $query = Commission::query()->with(['agent']);

                        if (! empty($data['agent_id'])) {
                            $query->where('agent_id', $data['agent_id']);
                        }
                        if (! empty($data['start_date'])) {
                            $query->whereDate('date_commission', '>=', $data['start_date']);
                        }
                        if (! empty($data['end_date'])) {
                            $query->whereDate('date_commission', '<=', $data['end_date']);
                        }

                        $commissions = $query->orderBy('date_commission', 'desc')->get();

                        $pdf = Pdf::loadView('exports.commissions-pdf', [
                            'commissions' => $commissions,
                            'filters' => [
                                'agent' => $data['agent_id'] ? Agent::find($data['agent_id'])?->name : 'Tous',
                                'start_date' => $data['start_date'] ?? 'Début',
                                'end_date' => $data['end_date'] ?? now()->format('d/m/Y'),
                            ],
                            'generatedAt' => now(),
                            'generatedBy' => Auth::user()?->name ?? 'Système',
                        ])->setPaper('a4', 'portrait');

                        return response()->streamDownload(
                            fn () => print ($pdf->output()),
                            'commissions_'.now()->format('Y-m-d_His').'.pdf'
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
