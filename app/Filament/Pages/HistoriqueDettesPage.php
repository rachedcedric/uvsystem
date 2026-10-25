<?php

namespace App\Filament\Pages;

use App\Filament\Resources\FournisseurResource;
use App\Livewire\Concerns\WithReportPeriod;
use App\Services\Reports\Categories\HistoriqueDettes;
use App\Services\Reports\ClotureHistoryService;
use App\Services\Reports\DateRange;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Historique des dettes et crédits (Web) : jour après jour, les agents et fournisseurs dont le solde n'était pas
 * nul à la clôture (23h59), tels qu'ils ont été figés ce jour-là. Même source que l'écran mobile et les exports
 * (ClotureHistoryService) ; les filtres portent sur les clôtures enregistrées, jamais sur les soldes actuels.
 */
class HistoriqueDettesPage extends Page
{
    use WithPagination;
    use WithReportPeriod;

    public const PAGE = 15;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Historique des dettes et crédits';

    protected static ?string $title = 'Historique des dettes et crédits';

    protected static ?string $slug = 'historique-dettes';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.historique-dettes-page';

    #[Url(as: 'type', except: 'all')]
    public string $type = 'all';

    #[Url(as: 'nature', except: '')]
    public string $nature = '';

    #[Url(as: 'jours', except: '')]
    public string $jours = '';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public static function canAccess(): bool
    {
        return (bool) (Auth::user()?->can('view_remboursements') || Auth::user()?->can('view_remboursements_fournisseurs'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'nature', 'jours', 'q'], true)) {
            $this->resetPage();
        }
    }

    protected function onPeriodChanged(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('type', 'nature', 'jours', 'q');
        $this->resetPage();
    }

    /** @return array<string, mixed> */
    public function historyData(): array
    {
        [$range, $error] = $this->resolveRange();
        $filters = $this->filters();

        return [
            'range' => $range,
            'error' => $error,
            'presets' => DateRange::PRESETS,
            'types' => ClotureHistoryService::TYPES,
            'natures' => ClotureHistoryService::NATURES,
            'jourOptions' => ClotureHistoryService::JOURS,
            'days' => $range ? app(ClotureHistoryService::class)->jours($range, (new HistoriqueDettes)->filters($filters), self::PAGE, $this->getPage()) : null,
            'active' => ClotureHistoryService::filtresActifs($filters) || $this->jours !== '',
            'exportQuery' => $range ? $range->toQuery() + $filters : [],
            'canExport' => Auth::user()->can('export_reports') || Auth::user()->hasAnyRole(['master', 'admin']),
        ];
    }

    /** @return array<string, string> filtres valides et non vides (mêmes paramètres que l'export) */
    private function filters(): array
    {
        return array_filter([
            'type' => array_key_exists($this->type, ClotureHistoryService::TYPES) ? $this->type : 'all',
            'nature' => array_key_exists($this->nature, ClotureHistoryService::NATURES) ? $this->nature : '',
            'jours' => array_key_exists($this->jours, ClotureHistoryService::JOURS) ? $this->jours : '',
            'q' => trim($this->q),
        ], fn ($v) => $v !== '' && $v !== 'all');
    }

    public static function detailUrl(array $line): string
    {
        return $line['type'] === 'agent'
            ? AgentDebtDetailPage::getUrl(['agent' => $line['id']])
            : FournisseurResource::getUrl('view', ['record' => $line['id']]);
    }
}
