<?php

namespace App\Filament\Pages;

use App\Models\CaisseCategorie;
use App\Models\CaisseMouvement;
use App\Services\CaissePeriodResolver;
use App\Services\CaisseService;
use App\Services\TenantDocumentBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Détails d'une journée de caisse : mouvements, résumé et soldes par catégorie. */
class MaCaisseJourPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'ma-caisse/{date}';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.ma-caisse-jour-page';

    public string $date = '';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('view_caisse');
    }

    public function mount(string $date): void
    {
        try {
            $this->date = Carbon::createFromFormat('Y-m-d', $date, CaissePeriodResolver::TIMEZONE)->toDateString();
        } catch (\Throwable) {
            abort(404);
        }
    }

    public function businessDate(): Carbon
    {
        return Carbon::parse($this->date, CaissePeriodResolver::TIMEZONE)->startOfDay();
    }

    public function getTitle(): string
    {
        return 'Caisse du '.$this->businessDate()->format('d/m/Y');
    }

    public function getBreadcrumbs(): array
    {
        return [MaCaissePage::getUrl() => 'Ma Caisse', '#' => $this->businessDate()->format('d/m/Y')];
    }

    public function totals(): array
    {
        return app(CaisseService::class)->dayTotals($this->businessDate());
    }

    public function categoryRows()
    {
        return app(CaisseService::class)->categoryBalances($this->businessDate(), $this->businessDate());
    }

    public function periodLabel(): string
    {
        $b = app(CaissePeriodResolver::class);

        return $this->businessDate()->format('d/m/Y').' 00h00 → '.$b->endLabelMoment($this->businessDate())->format('d/m/Y').' 23h59';
    }

    public function table(Table $table): Table
    {
        $service = app(CaisseService::class);
        $balances = $service->dayRunningBalances($this->businessDate());

        return $table
            ->query($service->mouvementsQuery($this->businessDate())->with(['categorie', 'user']))
            ->columns([
                Tables\Columns\TextColumn::make('date_mouvement')->label('Date et heure')
                    ->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('type')->label('Type')->badge()
                    ->formatStateUsing(fn (string $state) => $state === CaisseMouvement::TYPE_ENTREE ? 'Entrée' : 'Sortie')
                    ->color(fn (string $state) => $state === CaisseMouvement::TYPE_ENTREE ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('categorie.name')->label('Catégorie')->badge()->color('gray')
                    ->placeholder('Non catégorisé'),
                Tables\Columns\TextColumn::make('libelle')->label('Libellé')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('amount')->label('Montant')->alignEnd()->weight('bold')
                    ->formatStateUsing(fn ($state, CaisseMouvement $r) => ($r->type === CaisseMouvement::TYPE_ENTREE ? '+' : '−').CaisseService::fcfa($state))
                    ->color(fn (CaisseMouvement $r) => $r->type === CaisseMouvement::TYPE_ENTREE ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('solde_apres')->label('Solde après')->alignEnd()
                    ->getStateUsing(fn (CaisseMouvement $r) => isset($balances[$r->id]) ? CaisseService::fcfa($balances[$r->id]['solde_apres']) : '—'),
                Tables\Columns\TextColumn::make('user.name')->label('Enregistré par')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('caisse_categorie_id')->label('Catégorie')
                    ->options(fn () => CaisseCategorie::orderBy('sort_order')->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('type')->label('Type')
                    ->options([CaisseMouvement::TYPE_ENTREE => 'Entrée', CaisseMouvement::TYPE_SORTIE => 'Sortie']),
            ])
            ->defaultSort('date_mouvement', 'asc')
            ->actions([
                Tables\Actions\EditAction::make()
                    ->form([
                        Forms\Components\Select::make('type')->label('Type')->required()->native(false)
                            ->options([CaisseMouvement::TYPE_ENTREE => 'Entrée', CaisseMouvement::TYPE_SORTIE => 'Sortie']),
                        Forms\Components\Select::make('caisse_categorie_id')->label('Catégorie')->required()->native(false)
                            ->options(fn () => MaCaissePage::activeCategoryOptions()),
                        Forms\Components\TextInput::make('libelle')->label('Libellé')->required()->maxLength(255),
                        Forms\Components\TextInput::make('amount')->label('Montant')->money(min: 1)->required(),
                        Forms\Components\DateTimePicker::make('date_mouvement')->label('Date et heure')
                            ->required()->maxDate(now())->seconds(false)->native(false),
                    ])
                    ->using(function (CaisseMouvement $record, array $data) {
                        try {
                            app(CaisseService::class)->validateMouvement($data['type'], (float) $data['amount']);
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title('Validation échouée')
                                ->body(collect($e->errors())->flatten()->implode(' '))->persistent()->send();
                            throw $e;
                        }
                        $record->update($data);

                        return $record;
                    })
                    ->visible(fn () => Auth::user()?->can('edit_caisse') ?? false),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => Auth::user()?->can('delete_caisse') ?? false),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Aucun mouvement ce jour-là');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label('Retour')->icon('heroicon-o-arrow-left')->color('gray')
                ->url(MaCaissePage::getUrl()),
            Action::make('exportPdf')->label('Exporter PDF')->icon('heroicon-o-arrow-up-tray')
                ->action(function () {
                    $service = app(CaisseService::class);
                    $date = $this->businessDate();
                    $pdf = Pdf::loadView('exports.caisse-jour-pdf', [
                        'tenant' => app(TenantDocumentBranding::class)->tenantName(),
                        'periode' => $this->periodLabel(),
                        'date' => $date,
                        'totals' => $this->totals(),
                        'mouvements' => $service->mouvementsQuery($date)->with(['categorie', 'user'])->orderBy('date_mouvement')->orderBy('id')->get(),
                        'balances' => $service->dayRunningBalances($date),
                        'categories' => $this->categoryRows(),
                        'generatedAt' => now(),
                    ])->setPaper('a4', 'portrait');

                    return response()->streamDownload(
                        fn () => print ($pdf->output()),
                        'caisse_'.$date->format('Y-m-d').'.pdf'
                    );
                }),
        ];
    }
}
