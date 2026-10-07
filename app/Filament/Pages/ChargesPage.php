<?php

namespace App\Filament\Pages;

use App\Exports\ChargesExport;
use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Services\ChargeStatisticsService;
use App\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Gestion des Charges : suivi des charges d'exploitation et du revenu net
 * réel de l'activité (commissions générées par les ventes − charges).
 *
 * Le "Total Revenu" provient exclusivement de Commission::commission_globale
 * (jamais du chiffre d'affaires/SUM(ventes)) via ChargeStatisticsService,
 * qui réutilise exactement la même requête que MonthlyPerformanceWidget —
 * aucune deuxième formule de commission n'est introduite ici.
 */
class ChargesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Gestion des Charges';

    // Filament dérive par défaut "charges-page" du nom de la classe : forcé
    // ici pour correspondre exactement à /master/charges.
    protected static ?string $slug = 'charges';

    protected static ?string $title = 'Gestion des Charges';

    protected static ?string $navigationGroup = 'Gestion des Charges';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.charges-page';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('view_charges');
    }

    /**
     * Résumé des 3 tuiles, recalculé à chaque changement du filtre "period"
     * (InteractsWithTable expose $tableFilters comme propriété Livewire
     * réactive — Blade rappelle cette méthode à chaque rendu, donc aucune
     * mise à jour manuelle n'est nécessaire après création/modification/
     * suppression d'une charge ou après un nouveau calcul de commission).
     *
     * Volontairement : le filtre "Catégorie" n'intervient JAMAIS ici, seul
     * le filtre "Période" pilote les tuiles — sinon "Revenu net" cesserait
     * de représenter le net réel de la période affichée.
     */
    public function getSummary(): array
    {
        $periodFilter = $this->tableFilters['period'] ?? [];

        return app(ChargeStatisticsService::class)->summary(
            $periodFilter['value'] ?? null,
            $periodFilter['from'] ?? null,
            $periodFilter['until'] ?? null,
        );
    }

    public static function chargeFormSchema(): array
    {
        return [
            Forms\Components\Section::make('Détails de la charge')
                ->schema([
                    Forms\Components\TextInput::make('libelle')
                        ->label('Libellé / Nom de la charge')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),

                    Forms\Components\Select::make('category_id')
                        ->label('Catégorie')
                        ->options(fn () => ChargeCategorie::getActiveOptions())
                        ->searchable()
                        ->required(),

                    Forms\Components\TextInput::make('montant')
                        ->label('Montant')
                        ->money(min: 1)
                        ->required(),

                    Forms\Components\DatePicker::make('date_charge')
                        ->label('Date')
                        ->required()
                        ->default(now())
                        ->maxDate(now())
                        ->native(false)
                        ->displayFormat('d/m/Y'),

                    Forms\Components\TimePicker::make('heure_charge')
                        ->label('Heure')
                        ->seconds(false),

                    Forms\Components\Textarea::make('description')
                        ->label('Description / Observation')
                        ->rows(3)
                        ->columnSpanFull(),

                    Forms\Components\FileUpload::make('justificatif_path')
                        ->label('Justificatif (facultatif)')
                        ->disk('local')
                        ->directory(fn () => TenantContext::storagePath('charges'))
                        ->visibility('private')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                        ->maxSize(5120)
                        ->helperText('PDF ou image, 5 Mo maximum')
                        ->columnSpanFull(),

                    Forms\Components\Placeholder::make('registered_by')
                        ->label('Enregistré par')
                        ->content(fn (?Charge $record) => $record
                            ? ($record->creator?->name ?? 'Système').' le '.$record->created_at->format('d/m/Y à H:i')
                            : null)
                        ->visible(fn (?Charge $record) => $record !== null)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Charge::query()->with(['category', 'creator']))
            ->columns([
                Tables\Columns\TextColumn::make('date_charge')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('heure_charge')
                    ->label('Heure')
                    ->formatStateUsing(fn ($state) => $state ? \Carbon\Carbon::parse($state)->format('H:i') : '—'),

                Tables\Columns\TextColumn::make('libelle')
                    ->label('Libellé')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Catégorie')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('montant')
                    ->label('Montant')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ').' FCFA')
                    ->sortable()
                    ->weight('bold')
                    ->color('danger')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(30)
                    ->tooltip(fn (Charge $record) => $record->description)
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Enregistré par')
                    ->searchable()
                    ->placeholder('Système')
                    ->toggleable(),
            ])
            ->defaultSort('date_charge', 'desc')
            ->filters([
                Tables\Filters\Filter::make('period')
                    ->label('Période')
                    ->form([
                        Forms\Components\Select::make('value')
                            ->label('Période')
                            ->options([
                                ChargeStatisticsService::PERIOD_TODAY => "Aujourd'hui",
                                ChargeStatisticsService::PERIOD_THIS_WEEK => 'Cette semaine',
                                ChargeStatisticsService::PERIOD_THIS_MONTH => 'Ce mois',
                                ChargeStatisticsService::PERIOD_LAST_MONTH => 'Mois précédent',
                                ChargeStatisticsService::PERIOD_CUSTOM => 'Période personnalisée',
                            ])
                            ->default(ChargeStatisticsService::PERIOD_THIS_MONTH)
                            ->live()
                            ->native(false),

                        Forms\Components\DatePicker::make('from')
                            ->label('Du')
                            ->native(false)
                            ->visible(fn (Get $get) => $get('value') === ChargeStatisticsService::PERIOD_CUSTOM),

                        Forms\Components\DatePicker::make('until')
                            ->label('Au')
                            ->native(false)
                            ->visible(fn (Get $get) => $get('value') === ChargeStatisticsService::PERIOD_CUSTOM),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $period = app(ChargeStatisticsService::class)->resolvePeriod(
                            $data['value'] ?? null,
                            $data['from'] ?? null,
                            $data['until'] ?? null,
                        );

                        return $query->forPeriod($period['start'], $period['end']);
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $period = app(ChargeStatisticsService::class)->resolvePeriod(
                            $data['value'] ?? null,
                            $data['from'] ?? null,
                            $data['until'] ?? null,
                        );

                        return $period['label'];
                    }),

                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Catégorie')
                    ->options(fn () => ChargeCategorie::getActiveOptions())
                    ->searchable(),
            ])
            ->filtersFormColumns(3)
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()
                        ->form(static::chargeFormSchema())
                        ->using(function (Charge $record, array $data) {
                            $data['updated_by'] = Auth::id();
                            $record->update($data);

                            return $record;
                        })
                        ->visible(fn () => Auth::user()?->can('edit_charges') ?? false),

                    Tables\Actions\Action::make('justificatif')
                        ->label('Voir le justificatif')
                        ->icon('heroicon-o-paper-clip')
                        ->color('gray')
                        ->url(fn (Charge $record) => route('charges.justificatif.download', $record))
                        ->openUrlInNewTab()
                        ->visible(fn (Charge $record) => filled($record->justificatif_path)),

                    Tables\Actions\DeleteAction::make()
                        ->visible(fn () => Auth::user()?->can('delete_charges') ?? false),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Actions'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('add')
                    ->label('Ajouter une charge')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->form(static::chargeFormSchema())
                    ->visible(fn () => Auth::user()?->can('create_charges') ?? false)
                    ->action(function (array $data) {
                        $data['created_by'] = Auth::id();
                        Charge::create($data);
                    }),

                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('exportExcel')
                        ->label('Excel')
                        ->icon('heroicon-o-table-cells')
                        ->color('success')
                        ->action(fn () => $this->exportPeriode(ExcelFormat::XLSX, 'xlsx')),

                    Tables\Actions\Action::make('exportCsv')
                        ->label('CSV')
                        ->icon('heroicon-o-document-text')
                        ->color('gray')
                        ->action(fn () => $this->exportPeriode(ExcelFormat::CSV, 'csv')),

                    Tables\Actions\Action::make('exportPdf')
                        ->label('PDF')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('danger')
                        ->action(fn () => $this->exportPdf()),
                ])
                    ->label('Exporter')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->button()
                    ->visible(fn () => Auth::user()?->can('export_charges') ?? false),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->striped()
            ->emptyStateHeading('Aucune charge enregistrée')
            ->emptyStateDescription('Ajoutez votre première charge d\'exploitation pour suivre votre revenu net.')
            ->emptyStateIcon('heroicon-o-receipt-percent');
    }

    protected function exportPeriode(string $format, string $extension): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $summary = $this->getSummary();
        $periodFilter = $this->tableFilters['period'] ?? [];
        $categoryId = $this->tableFilters['category_id']['value'] ?? null;

        $charges = Charge::query()
            ->with(['category', 'creator'])
            ->forPeriod($summary['start'], $summary['end'])
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->orderByDesc('date_charge')
            ->get();

        return Excel::download(
            new ChargesExport($summary, $charges),
            'charges_'.now()->format('Y-m-d_His').'.'.$extension,
            $format
        );
    }

    protected function exportPdf(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $summary = $this->getSummary();
        $categoryId = $this->tableFilters['category_id']['value'] ?? null;

        $charges = Charge::query()
            ->with(['category', 'creator'])
            ->forPeriod($summary['start'], $summary['end'])
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->orderByDesc('date_charge')
            ->get();

        $pdf = Pdf::loadView('exports.charges-pdf', [
            'summary' => $summary,
            'charges' => $charges,
            'generatedAt' => now(),
            'generatedBy' => Auth::user()?->name ?? 'Système',
        ])->setPaper('a4', 'portrait');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'charges_'.now()->format('Y-m-d_His').'.pdf'
        );
    }
}
