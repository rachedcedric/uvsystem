<?php

namespace App\Filament\Actions;

use App\Services\Reports\DateRange;
use App\Services\Reports\ReportCatalog;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * « Exporter une période » (en-tête des listes Web) : raccourci ou dates libres, PDF ou Excel, vers le même export
 * que « Rapports et exports » (ReportExportController). La période est validée avant le téléchargement.
 */
class ExportPeriodAction
{
    public static function make(string $category): Action
    {
        return Action::make('exportPeriode')
            ->label('Exporter une période')
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->visible(fn () => (app(ReportCatalog::class)->get($category)?->allows(auth()->user()) ?? false) && ! auth()->user()?->isReadOnly())
            ->modalHeading(fn () => 'Exporter : '.app(ReportCatalog::class)->get($category)?->label())
            ->modalDescription('Les opérations de la période choisie (date de fin incluse), en PDF ou en Excel.')
            ->modalSubmitActionLabel('Exporter')
            ->form([
                Forms\Components\Select::make('periode')->label('Période')->options(DateRange::PRESETS)->default('month')->required()->live()->native(false),
                Forms\Components\DatePicker::make('du')->label('Date de début')->native(false)->displayFormat('d/m/Y')->maxDate(now())
                    ->default(now()->startOfMonth())->visible(fn (Forms\Get $get) => $get('periode') === 'custom')->required(fn (Forms\Get $get) => $get('periode') === 'custom'),
                Forms\Components\DatePicker::make('au')->label('Date de fin')->native(false)->displayFormat('d/m/Y')
                    ->default(now())->afterOrEqual('du')->visible(fn (Forms\Get $get) => $get('periode') === 'custom')->required(fn (Forms\Get $get) => $get('periode') === 'custom'),
                Forms\Components\ToggleButtons::make('format')->label('Format')->options(['pdf' => 'PDF', 'xlsx' => 'Excel'])->default('pdf')->inline()->required(),
            ])
            ->action(function (array $data, Action $action) use ($category) {
                try {
                    $range = DateRange::fromInput([
                        'periode' => $data['periode'],
                        'du' => isset($data['du']) ? substr((string) $data['du'], 0, 10) : null,
                        'au' => isset($data['au']) ? substr((string) $data['au'], 0, 10) : null,
                    ]);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Période incohérente')->body(collect($e->errors())->flatten()->implode(' '))->send();
                    $action->halt();
                }

                return redirect()->to(route('exports.categorie', [$category, $data['format']] + $range->toQuery()));
            });
    }
}
