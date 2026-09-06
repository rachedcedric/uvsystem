<?php

namespace App\Filament\Pages;

use App\Exports\DettesExport;
use App\Models\Agent;
use App\Services\BusinessPeriodResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Page détail de la dette d'un agent (/admin/dettes/{agent}), distincte de
 * la page liste (DettesPage) : les 4 tuiles ici sont Dette du jour / Total
 * dette / Remboursé / Reste à payer, propres à CET agent — à ne jamais
 * confondre avec les 4 tuiles globales de la liste.
 *
 * Total dette / Remboursé / Reste à payer réutilisent exactement les mêmes
 * colonnes que DettesPage (AgentSolde, Agent::current_debt) : aucune
 * deuxième formule. Seule "Dette du jour" est une nouvelle agrégation
 * (bornée à la journée métier en cours via BusinessPeriodResolver).
 */
class AgentDebtDetailPage extends Page
{
    protected static string $view = 'filament.pages.agent-debt-detail-page';

    // Route nommée propre ("pages.agent-debt-detail"), indépendante du chemin
    // d'URL réel défini par getRoutePath() ci-dessous.
    protected static ?string $slug = 'agent-debt-detail';

    protected static bool $shouldRegisterNavigation = false;

    private const PER_PAGE = 15;

    public Agent $agent;

    public int $page = 1;

    public static function getRoutePath(): string
    {
        return '/dettes/{agent}';
    }

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('view_remboursements');
    }

    public function mount(Agent $agent): void
    {
        $this->agent = $agent;
    }

    public function getTitle(): string
    {
        return 'Dette de '.$this->agent->name;
    }

    public function nextPage(): void
    {
        $totalPages = max(1, (int) ceil($this->getTotalCount() / self::PER_PAGE));
        $this->page = min($this->page + 1, $totalPages);
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    /**
     * @return array{detteDuJour: float, totalDette: float, rembourse: float, resteAPayer: float}
     */
    public function getSummary(): array
    {
        $today = app(BusinessPeriodResolver::class)->today();

        $distribueAujourdhui = (float) $this->agent->transactions()
            ->where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('transaction_date', [$today['start'], $today['end']])
            ->sum('amount');

        $retourneAujourdhui = (float) $this->agent->transactions()
            ->where('transaction_type', 'retour')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('transaction_date', [$today['start'], $today['end']])
            ->sum('amount');

        return [
            'detteDuJour' => $distribueAujourdhui - $retourneAujourdhui,
            'totalDette' => (float) ($this->agent->solde?->total_uv_distribues ?? $this->agent->current_debt),
            'rembourse' => (float) ($this->agent->solde?->total_rembourse ?? 0),
            'resteAPayer' => (float) $this->agent->current_debt,
        ];
    }

    /**
     * Union ventes/retours (avec solde_uv_avant/apres) + remboursements
     * (sans snapshot de solde), restreinte à cet agent — même schéma que
     * RealTimeActivityWidget::buildUnionQuery(), scopé par agent_id.
     */
    private function buildHistoryUnionQuery(): \Illuminate\Database\Query\Builder
    {
        $ventes = DB::table('uv_transactions')
            ->where('agent_id', $this->agent->id)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->select([
                'transaction_type as type',
                'id',
                'reference',
                'amount',
                DB::raw('NULL as remboursement_amount'),
                'solde_uv_avant',
                'solde_uv_apres',
                'transaction_date as date_operation',
            ]);

        $remboursements = DB::table('remboursements')
            ->where('agent_id', $this->agent->id)
            ->whereNull('deleted_at')
            ->select([
                DB::raw("'remboursement' as type"),
                'id',
                'reference',
                DB::raw('NULL as amount'),
                'amount as remboursement_amount',
                DB::raw('NULL as solde_uv_avant'),
                DB::raw('NULL as solde_uv_apres'),
                'payment_date as date_operation',
            ]);

        return $ventes->unionAll($remboursements);
    }

    private function getTotalCount(): int
    {
        $union = $this->buildHistoryUnionQuery();

        return DB::table(DB::raw('('.$union->toSql().') as historique'))
            ->mergeBindings($union)
            ->count();
    }

    /**
     * @return array{items: \Illuminate\Support\Collection, total: int, page: int, totalPages: int}
     */
    public function getHistory(): array
    {
        $total = $this->getTotalCount();
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        if ($this->page > $totalPages) {
            $this->page = $totalPages;
        }

        $union = $this->buildHistoryUnionQuery();

        $rows = DB::table(DB::raw('('.$union->toSql().') as historique'))
            ->mergeBindings($union)
            ->orderByDesc('date_operation')
            ->orderByDesc('id')
            ->limit(self::PER_PAGE)
            ->offset(self::PER_PAGE * ($this->page - 1))
            ->get();

        return [
            'items' => $rows,
            'total' => $total,
            'page' => $this->page,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Historique COMPLET (sans pagination) pour les exports Excel/PDF : le
     * fichier exporté doit contenir toutes les lignes de cet agent, jamais
     * seulement la page actuellement affichée à l'écran.
     */
    public function getFullHistory(): \Illuminate\Support\Collection
    {
        $union = $this->buildHistoryUnionQuery();

        return DB::table(DB::raw('('.$union->toSql().') as historique'))
            ->mergeBindings($union)
            ->orderByDesc('date_operation')
            ->orderByDesc('id')
            ->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('encaisser')
                ->label('Encaisser')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->url(fn () => "/admin/remboursements/create?agent_id={$this->agent->id}")
                ->visible(fn () => $this->agent->current_debt > 0 && (Auth::user()?->can('create_remboursements') ?? false)),

            Actions\ActionGroup::make([
                Actions\Action::make('exportExcel')
                    ->label('Exporter Excel')
                    ->icon('heroicon-o-table-cells')
                    ->color('success')
                    ->action(function () {
                        $filename = 'dette_'.Str::slug($this->agent->name).'_'.now()->format('Y-m-d_His').'.xlsx';
                        $path = 'exports/'.$filename;

                        Excel::store(
                            new DettesExport($this->agent, $this->getSummary(), $this->getFullHistory()),
                            $path,
                            'public'
                        );

                        return redirect()->route('exports.download', ['filename' => $filename]);
                    }),

                Actions\Action::make('exportPdf')
                    ->label('Exporter PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('danger')
                    ->action(function () {
                        $pdf = Pdf::loadView('exports.dette-agent-pdf', [
                            'agent' => $this->agent,
                            'summary' => $this->getSummary(),
                            'history' => $this->getFullHistory(),
                            'generatedAt' => now(),
                            'generatedBy' => Auth::user()?->name ?? 'Système',
                        ])->setPaper('a4', 'portrait');

                        return response()->streamDownload(
                            fn () => print ($pdf->output()),
                            'dette_'.Str::slug($this->agent->name).'_'.now()->format('Y-m-d_His').'.pdf'
                        );
                    }),
            ])
                ->label('Exporter')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->button(),
        ];
    }
}
