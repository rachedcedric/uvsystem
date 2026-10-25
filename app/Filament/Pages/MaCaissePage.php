<?php

namespace App\Filament\Pages;

use App\Filament\Actions\ExportPeriodAction;
use App\Filament\Resources\CaisseCategorieResource;
use App\Models\CaisseCategorie;
use App\Models\CaisseMouvement;
use App\Services\CaissePeriodResolver;
use App\Services\CaisseService;
use App\Services\TenantDocumentBranding;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * "Ma Caisse" : historique regroupé par journée civile (00h00 → 23h59,
 * Africa/Abidjan). Tout calcul passe par CaisseService (source unique).
 */
class MaCaissePage extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationLabel = 'Ma Caisse';

    protected static ?string $slug = 'ma-caisse';

    protected static ?string $title = 'Ma Caisse';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.ma-caisse-page';

    #[Url]
    public string $search = '';

    #[Url]
    public ?string $dateFrom = null;

    #[Url]
    public ?string $dateTo = null;

    public int $statsKey = 0;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('view_caisse');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'dateFrom', 'dateTo');
        $this->resetPage();
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value, CaissePeriodResolver::TIMEZONE)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public function summaries()
    {
        return app(CaisseService::class)->daySummaries(
            $this->parseDate($this->dateFrom),
            $this->parseDate($this->dateTo),
            $this->search,
        );
    }

    public function getPaginatedDays(): LengthAwarePaginator
    {
        $all = $this->summaries();
        $perPage = 10;
        $page = max(1, $this->getPage());

        return new LengthAwarePaginator(
            $all->forPage($page, $perPage)->values(),
            $all->count(),
            $perPage,
            $page,
            ['path' => static::getUrl()]
        );
    }

    /** @return array<int, string> */
    public static function activeCategoryOptions(): array
    {
        return CaisseCategorie::active()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function mouvementsFormSchema(): array
    {
        return [
            Forms\Components\Repeater::make('mouvements')
                ->label('')
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('Type')
                        ->options([
                            CaisseMouvement::TYPE_ENTREE => 'Entrée',
                            CaisseMouvement::TYPE_SORTIE => 'Sortie',
                        ])
                        ->default(CaisseMouvement::TYPE_ENTREE)
                        ->required()->native(false)->live()
                        ->columnSpan(2),

                    Forms\Components\Select::make('caisse_categorie_id')
                        ->label('Catégorie caisse')
                        ->options(fn () => static::activeCategoryOptions())
                        ->required()->native(false)->searchable()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')
                                ->label('Nom de la catégorie')->required()->maxLength(100)
                                ->rules([fn () => function (string $attribute, $value, \Closure $fail) {
                                    if (CaisseCategorie::nameIsTaken((string) $value)) {
                                        $fail('Une catégorie portant ce nom existe déjà.');
                                    }
                                }]),
                            Forms\Components\ColorPicker::make('color')->label('Couleur (optionnel)')->hex(),
                        ])
                        ->createOptionModalHeading('Nouvelle catégorie caisse')
                        ->createOptionAction(fn (Forms\Components\Actions\Action $action) => $action
                            ->modalSubmitActionLabel('Ajouter')
                            ->visible(fn () => (bool) Auth::user()?->can('create_caisse')))
                        ->createOptionUsing(fn (array $data): int => CaisseCategorie::create([
                            'name' => $data['name'],
                            'color' => $data['color'] ?? null,
                            'sort_order' => ((int) CaisseCategorie::max('sort_order')) + 1,
                            'is_active' => true,
                        ])->id)
                        ->suffixAction(
                            Forms\Components\Actions\Action::make('manageCategories')
                                ->icon('heroicon-o-cog-6-tooth')
                                ->tooltip('Gérer les catégories')
                                ->url(CaisseCategorieResource::getUrl(), shouldOpenInNewTab: true)
                        )
                        ->columnSpan(4),

                    Forms\Components\TextInput::make('libelle')
                        ->label('Libellé')->required()->maxLength(255)
                        ->placeholder('Libellé du mouvement')
                        ->columnSpan(3),

                    Forms\Components\TextInput::make('amount')
                        ->label('Montant (FCFA)')
                        ->money(min: 1)->required()->live(onBlur: true)
                        ->columnSpan(3),
                ])
                ->columns(12)
                ->defaultItems(1)
                ->minItems(1)
                ->addActionLabel('+ Ajouter une ligne')
                ->deleteAction(fn (Forms\Components\Actions\Action $action) => $action->label('Supprimer la ligne'))
                ->live()
                ->reorderable(false),

            Forms\Components\DateTimePicker::make('caisse_date')
                ->label('Date et heure de caisse')
                ->default(now())
                ->maxDate(now())
                ->seconds(false)
                ->required()->native(false)->displayFormat('d/m/Y H:i'),

            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Placeholder::make('total_entrees')->label('Total entrées')
                    ->content(fn (Get $get) => CaisseService::fcfa(static::sumLines($get('mouvements'), CaisseMouvement::TYPE_ENTREE))),
                Forms\Components\Placeholder::make('total_sorties')->label('Total sorties')
                    ->content(fn (Get $get) => CaisseService::fcfa(static::sumLines($get('mouvements'), CaisseMouvement::TYPE_SORTIE))),
                Forms\Components\Placeholder::make('solde_calcule')->label('Solde calculé')
                    ->content(fn (Get $get) => CaisseService::fcfa(
                        static::sumLines($get('mouvements'), CaisseMouvement::TYPE_ENTREE) - static::sumLines($get('mouvements'), CaisseMouvement::TYPE_SORTIE)
                    )),
            ]),
        ];
    }

    public static function sumLines(?array $lignes, string $type): float
    {
        return collect($lignes ?? [])
            ->where('type', $type)
            ->sum(fn ($l) => Money::toFloat($l['amount'] ?? 0));
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportPeriodAction::make('mouvements'),
            Action::make('add')
                ->label('Ajouter un mouvement')
                ->icon('heroicon-o-plus')
                ->modalHeading('Ajouter des mouvements')
                ->modalDescription('Enregistrez tous les mouvements de la caisse')
                ->modalWidth('5xl')
                ->modalSubmitActionLabel('Enregistrer les mouvements')
                ->modalCancelActionLabel('Annuler')
                ->form(static::mouvementsFormSchema())
                ->visible(fn () => (bool) Auth::user()?->can('create_caisse'))
                ->action(function (array $data, Action $action) {
                    try {
                        $created = app(CaisseService::class)->enregistrerLot($data['mouvements'], $data['caisse_date'] ?? null);
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title('Enregistrement impossible')
                            ->body(collect($e->errors())->flatten()->implode(' '))->persistent()->send();
                        $action->halt();

                        return;
                    }

                    $this->statsKey++;
                    Notification::make()->success()
                        ->title($created->count().' mouvement(s) enregistré(s)')->send();
                }),

            Action::make('exportPdf')
                ->label('Exporter PDF')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->action(fn () => $this->exportPdf()),

            Action::make('categories')
                ->label('Gérer les catégories')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->url(CaisseCategorieResource::getUrl()),
        ];
    }

    public function exportPdf()
    {
        $service = app(CaisseService::class);
        $from = $this->parseDate($this->dateFrom);
        $to = $this->parseDate($this->dateTo);
        $days = $this->summaries();

        $entrees = $days->reduce(fn ($c, $d) => bcadd($c, $d['entrees'], 2), '0');
        $sorties = $days->reduce(fn ($c, $d) => bcadd($c, $d['sorties'], 2), '0');

        $pdf = Pdf::loadView('exports.caisse-historique-pdf', [
            'tenant' => app(TenantDocumentBranding::class)->tenantName(),
            'periode' => $this->periodLabel($from, $to),
            'days' => $days,
            'totalEntrees' => $entrees,
            'totalSorties' => $sorties,
            'solde' => bcsub($entrees, $sorties, 2),
            'categories' => $service->categoryBalances($from, $to),
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'caisse_historique_'.now()->format('Y-m-d_His').'.pdf'
        );
    }

    private function periodLabel(?Carbon $from, ?Carbon $to): string
    {
        if (! $from && ! $to) {
            return 'Toutes les journées';
        }

        return 'Du '.($from?->format('d/m/Y') ?? '…').' au '.($to?->format('d/m/Y') ?? '…');
    }

    public function getDetailUrl(Carbon $date): string
    {
        return MaCaisseJourPage::getUrl(['date' => $date->toDateString()]);
    }
}
