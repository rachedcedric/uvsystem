<?php

namespace App\Livewire\Mobile\Dettes;

use App\Livewire\Concerns\WithReportPeriod;
use App\Services\Reports\Categories\HistoriqueDettes;
use App\Services\Reports\ClotureHistoryService;
use App\Services\Reports\DateRange;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Historique des dettes et crédits (mobile) : une carte dépliable par journée, avec les soldes non nuls figés à la
 * clôture (23h59). Même source que le Web et les exports (ClotureHistoryService), jamais les soldes actuels.
 */
#[Layout('components.layouts.mobile')]
#[Title('Historique des dettes et crédits')]
class Historique extends Component
{
    use WithReportPeriod;

    public const PAGE = 10;

    #[Url(as: 'type', except: 'all')]
    public string $type = 'all';

    #[Url(as: 'nature', except: '')]
    public string $nature = '';

    #[Url(as: 'jours', except: '')]
    public string $jours = '';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public int $limit = self::PAGE;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view_remboursements') || auth()->user()->can('view_remboursements_fournisseurs'), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'nature', 'jours', 'q'], true)) {
            $this->limit = self::PAGE;
        }
    }

    public function loadMore(): void
    {
        $this->limit += self::PAGE;
    }

    public function resetFilters(): void
    {
        $this->reset('type', 'nature', 'jours', 'q');
        $this->limit = self::PAGE;
    }

    public function render()
    {
        [$range, $error] = $this->resolveRange();
        $filters = array_filter([
            'type' => array_key_exists($this->type, ClotureHistoryService::TYPES) ? $this->type : 'all',
            'nature' => array_key_exists($this->nature, ClotureHistoryService::NATURES) ? $this->nature : '',
            'jours' => array_key_exists($this->jours, ClotureHistoryService::JOURS) ? $this->jours : '',
            'q' => trim($this->q),
        ], fn ($v) => $v !== '' && $v !== 'all');
        $days = $range ? app(ClotureHistoryService::class)->jours($range, (new HistoriqueDettes)->filters($filters), $this->limit) : null;

        return view('livewire.mobile.dettes.historique', [
            'range' => $range,
            'error' => $error,
            'presets' => DateRange::PRESETS,
            'types' => ClotureHistoryService::TYPES,
            'natures' => ClotureHistoryService::NATURES,
            'jourOptions' => ClotureHistoryService::JOURS,
            'days' => $days,
            'hasMore' => $days && $days->hasMorePages(),
            'exportQuery' => $range ? $range->toQuery() + $filters : [],
            'canExport' => auth()->user()->can('export_reports') || auth()->user()->hasAnyRole(['master', 'admin']),
            'active' => $filters !== [],
        ]);
    }
}
