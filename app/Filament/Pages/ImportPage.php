<?php

namespace App\Filament\Pages;

use App\Imports\AgentsImport;
use App\Imports\RemboursementsImport;
use App\Imports\UvTransactionsImport;
use App\Services\Subscriptions\SubscriptionEntitlements;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Import CSV/Excel';

    protected static ?string $title = 'Import de Données';

    protected static ?string $navigationGroup = 'Données du tenant';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.import-page';

    public ?array $data = [];

    public ?string $importType = null;

    public ?array $importResults = null;

    /** Import en masse : master et admin uniquement (même règle que l'écran mobile `Import::allowed()`). */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['master', 'admin']);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        app(SubscriptionEntitlements::class)->abortUnlessFeature('import_donnees');
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Type d\'Import')
                    ->description('Sélectionnez le type de données à importer')
                    ->schema([
                        Select::make('import_type')
                            ->label('Type de données')
                            ->options([
                                'agents' => '👥 Agents',
                                'transactions' => '📦 Transactions UV',
                                'remboursements' => '💰 Remboursements',
                            ])
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(fn () => $this->importResults = null),
                    ]),

                Section::make('Fichier')
                    ->description('Formats acceptés: CSV, XLSX, XLS')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Fichier à importer')
                            ->acceptedFileTypes([
                                'text/csv',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ])
                            ->maxSize(10240) // 10MB
                            ->required()
                            ->helperText('Maximum 10 MB'),
                    ]),

                Section::make('Options')
                    ->schema([
                        Toggle::make('validate_debt_ceiling')
                            ->label('Valider les plafonds de dette')
                            ->helperText('Ignorer les lignes qui dépasseraient le plafond de dette')
                            ->default(true)
                            ->visible(fn (callable $get) => $get('import_type') === 'transactions'),

                        Toggle::make('validate_amount')
                            ->label('Valider les montants')
                            ->helperText('Ignorer les remboursements supérieurs à la dette')
                            ->default(true)
                            ->visible(fn (callable $get) => $get('import_type') === 'remboursements'),

                        Toggle::make('skip_duplicates')
                            ->label('Ignorer les doublons')
                            ->helperText('Les téléphones existants seront ignorés')
                            ->default(true)
                            ->visible(fn (callable $get) => $get('import_type') === 'agents'),
                    ]),

                Section::make('Format du fichier')
                    ->description('Colonnes requises selon le type')
                    ->collapsed()
                    ->schema([
                        Placeholder::make('agents_format')
                            ->label('Format Agents')
                            ->content('Colonnes: nom*, prenom*, telephone*, email, adresse, zone, commission_rate, plafond_dette, status, notes')
                            ->visible(fn (callable $get) => $get('import_type') === 'agents'),

                        Placeholder::make('transactions_format')
                            ->label('Format Transactions')
                            ->content('Colonnes: agent_id ou agent_telephone ou (agent_nom+agent_prenom)*, montant*, type, fournisseur_nom, date_transaction, notes')
                            ->visible(fn (callable $get) => $get('import_type') === 'transactions'),

                        Placeholder::make('remboursements_format')
                            ->label('Format Remboursements')
                            ->content('Colonnes: agent_id ou agent_telephone ou (agent_nom+agent_prenom)*, montant*, mode_paiement, reference_paiement, date_remboursement, notes')
                            ->visible(fn (callable $get) => $get('import_type') === 'remboursements'),
                    ]),
            ])
            ->statePath('data');
    }

    public function import(): void
    {
        abort_unless(static::canAccess(), 403);
        app(SubscriptionEntitlements::class)->abortUnlessFeature('import_donnees');
        $data = $this->form->getState();

        if (empty($data['file']) || empty($data['import_type'])) {
            Notification::make()
                ->title('Erreur')
                ->body('Veuillez sélectionner un type et un fichier')
                ->danger()
                ->send();

            return;
        }

        $filePath = Storage::disk('local')->path($data['file']);

        try {
            $import = match ($data['import_type']) {
                'agents' => new AgentsImport,
                'transactions' => new UvTransactionsImport($data['validate_debt_ceiling'] ?? true),
                'remboursements' => new RemboursementsImport($data['validate_amount'] ?? true),
            };

            Excel::import($import, $filePath);

            $rowCount = $import->getRowCount();
            $errors = collect($import->failures())->count();
            $successCount = method_exists($import, 'getSuccessCount')
                ? $import->getSuccessCount()
                : $rowCount - $errors;

            $this->importResults = [
                'total_rows' => $rowCount,
                'success' => $successCount,
                'errors' => $errors,
                'failures' => collect($import->failures())->take(10)->map(fn ($failure) => [
                    'row' => $failure->row(),
                    'attribute' => $failure->attribute(),
                    'errors' => $failure->errors(),
                ])->toArray(),
            ];

            // Delete temp file
            Storage::disk('local')->delete($data['file']);

            if ($errors === 0) {
                Notification::make()
                    ->title('Import réussi!')
                    ->body("$successCount lignes importées avec succès")
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Import partiel')
                    ->body("$successCount réussies, $errors erreurs")
                    ->warning()
                    ->send();
            }

            activity()
                ->withProperties([
                    'type' => $data['import_type'],
                    'rows' => $rowCount,
                    'success' => $successCount,
                    'errors' => $errors,
                ])
                ->log('Import CSV effectué');

        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur d\'import')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->importResults = [
                'error' => $e->getMessage(),
            ];
        }
    }

    public function downloadTemplate(): void
    {
        abort_unless(static::canAccess(), 403);
        $type = $this->data['import_type'] ?? null;

        if (! $type) {
            Notification::make()
                ->title('Sélectionnez un type')
                ->warning()
                ->send();

            return;
        }

        // This will be handled by a separate download route
        Notification::make()
            ->title('Fonctionnalité à venir')
            ->body('Le téléchargement de modèles sera disponible prochainement')
            ->info()
            ->send();
    }

    public function getViewData(): array
    {
        return [
            'importResults' => $this->importResults,
        ];
    }
}
