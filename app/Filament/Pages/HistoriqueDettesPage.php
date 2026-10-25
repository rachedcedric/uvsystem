<?php

namespace App\Filament\Pages;

use App\Filament\Resources\FournisseurResource;
use App\Livewire\Concerns\WithReportPeriod;
use App\Services\Reports\Categories\HistoriqueDettes;
use App\Services\Reports\DateRange;
use App\Services\Reports\DebtHistoryService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Historique des dettes et crédits (Web) : à chaque date, quels agents devaient au Master, quels fournisseurs étaient
 * créanciers ou détenaient une avance, les montants, les paiements et les soldes. Même source que l'écran mobile et
 * les exports (DebtHistoryService), soldes reconstitués depuis les mouvements enregistrés.
 */
class HistoriqueDettesPage extends Page
{
    use WithReportPeriod;

    public const PAGE = 50;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Historique des dettes et crédits';

    protected static ?string $title = 'Historique des dettes et crédits';

    protected static ?string $slug = 'historique-dettes';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.historique-dettes-page';

    #[Url(as: 'type', except: 'all')]
    public string $type = 'all';

    #[Url(as: 'statut', except: '')]
    public string $statut = '';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public int $limit = self::PAGE;

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
        if (in_array($property, ['type', 'statut', 'q'], true)) {
            $this->limit = self::PAGE;
        }
    }

    public function loadMore(): void
    {
        $this->limit += self::PAGE;
    }

    public function resetFilters(): void
    {
        $this->reset('type', 'statut', 'q');
        $this->limit = self::PAGE;
    }

    /** @return array<string, mixed> */
    public function historyData(): array
    {
        [$range, $error] = $this->resolveRange();
        $filters = array_filter(['type' => $this->type, 'statut' => $this->statut, 'q' => trim($this->q)]);
        $rows = $range ? (new HistoriqueDettes)->history($range, $filters) : collect();

        return [
            'range' => $range,
            'error' => $error,
            'presets' => DateRange::PRESETS,
            'types' => DebtHistoryService::TYPES,
            'statuts' => DebtHistoryService::STATUTS,
            'summary' => $range ? app(DebtHistoryService::class)->summary($range) : null,
            'rows' => $rows->take($this->limit),
            'count' => $rows->count(),
            'hasMore' => $rows->count() > $this->limit,
            'exportQuery' => $range ? $range->toQuery() + array_filter(['type' => $this->type !== 'all' ? $this->type : null, 'statut' => $this->statut, 'q' => trim($this->q)]) : [],
            'canExport' => Auth::user()->can('export_reports') || Auth::user()->hasAnyRole(['master', 'admin']),
        ];
    }

    public static function detailUrl(array $row): string
    {
        return $row['type'] === 'agent'
            ? AgentDebtDetailPage::getUrl(['agent' => $row['id']])
            : FournisseurResource::getUrl('view', ['record' => $row['id']]);
    }
}
