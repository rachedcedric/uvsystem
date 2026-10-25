<?php

namespace App\Livewire\Mobile\Dettes;

use App\Livewire\Concerns\WithReportPeriod;
use App\Services\Reports\Categories\HistoriqueDettes;
use App\Services\Reports\DateRange;
use App\Services\Reports\DebtHistoryService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Historique des dettes et crédits (mobile) : qui devait quoi, à qui, sur la période choisie, et le solde à la date
 * de fin — reconstitué depuis les mouvements enregistrés (DebtHistoryService, même source que le Web et les exports).
 */
#[Layout('components.layouts.mobile')]
#[Title('Historique des dettes et crédits')]
class Historique extends Component
{
    use WithReportPeriod;

    public const PAGE = 30;

    #[Url(as: 'type', except: 'all')]
    public string $type = 'all';

    #[Url(as: 'statut', except: '')]
    public string $statut = '';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public int $limit = self::PAGE;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view_remboursements') || auth()->user()->can('view_remboursements_fournisseurs'), 403);
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

    public function render()
    {
        [$range, $error] = $this->resolveRange();
        $filters = array_filter(['type' => $this->type, 'statut' => $this->statut, 'q' => trim($this->q)]);
        $category = new HistoriqueDettes;
        $rows = $range ? $category->history($range, $filters) : collect();

        return view('livewire.mobile.dettes.historique', [
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
            'canExport' => auth()->user()->can('export_reports') || auth()->user()->hasAnyRole(['master', 'admin']),
            'active' => $this->type !== 'all' || $this->statut !== '' || $this->q !== '',
        ]);
    }
}
