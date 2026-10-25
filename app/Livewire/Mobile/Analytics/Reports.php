<?php

namespace App\Livewire\Mobile\Analytics;

use App\Livewire\Concerns\WithReportPeriod;
use App\Services\Reports\DateRange;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportDashboard;
use App\Services\Reports\ReportPresenter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Rapports et exports (mobile) : tableau de bord de la période, puis une catégorie (ventes, approvisionnements,
 * remboursements, commissions, dettes, crédits et découverts, retours agents, mouvements…) avec ses opérations, sa
 * recherche et ses exports PDF / Excel. Même catalogue, mêmes chiffres et mêmes exports que l'interface Web.
 */
#[Layout('components.layouts.mobile')]
#[Title('Rapports et exports')]
class Reports extends Component
{
    use WithReportPeriod;

    public const PAGE = 30;

    #[Url(as: 'c', except: '')]
    public string $categorie = '';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public int $limit = self::PAGE;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('view_reports') || auth()->user()->can('export_reports'), 403);
    }

    public function updatedCategorie(): void
    {
        $this->q = '';
        $this->limit = self::PAGE;
    }

    public function updatedQ(): void
    {
        $this->limit = self::PAGE;
    }

    public function loadMore(): void
    {
        $this->limit += self::PAGE;
    }

    public function resetFilters(): void
    {
        $this->q = '';
        $this->limit = self::PAGE;
    }

    public function render()
    {
        $user = auth()->user();
        $catalog = app(ReportCatalog::class);
        $categories = $catalog->for($user);
        [$range, $error] = $this->resolveRange();
        $category = $categories[$this->categorie] ?? null;
        $data = [
            'categories' => $categories,
            'category' => $category,
            'range' => $range,
            'error' => $error,
            'canExport' => $user->can('export_reports') || $user->hasAnyRole(['master', 'admin']),
            'presets' => DateRange::PRESETS,
        ];

        if ($range && $category) {
            $filters = array_filter(['q' => trim($this->q)]);
            $rows = $category->rows($range, $filters, $this->limit + 1);

            return view('livewire.mobile.analytics.reports', $data + [
                'summary' => $category->summary($range, $filters),
                'rows' => $rows->take($this->limit)->map(fn (array $row) => ReportPresenter::mobile($row, $category->columns(), $category->label()) + ['raw' => $row['raw'] ?? null]),
                'hasMore' => $rows->count() > $this->limit,
                'exportQuery' => $range->toQuery() + $filters,
            ]);
        }

        return view('livewire.mobile.analytics.reports', $data + [
            'tiles' => $range ? app(ReportDashboard::class)->tiles($range, $user) : [],
        ]);
    }
}
