<?php

namespace App\Http\Controllers\Mobile;

use App\Exports\AgentSituationExport;
use App\Exports\ApprovisionnementExport;
use App\Exports\ChargesExport;
use App\Exports\CommissionsExport;
use App\Exports\DettesExport;
use App\Exports\RemboursementsExport;
use App\Exports\UvTransactionsExport;
use App\Filament\Pages\AgentDebtDetailPage;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Charge;
use App\Models\Commission;
use App\Models\PaymentMethod;
use App\Models\UvTransaction;
use App\Services\CaissePeriodResolver;
use App\Services\CaisseService;
use App\Services\Mobile\AgentHistory;
use App\Services\Mobile\PeriodStats;
use App\Services\TenantDocumentBranding;
use App\Support\Mobile\ChargePeriods;
use App\Support\PdfLimits;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Exports téléchargés depuis l'application. Mêmes vues et mêmes classes
 * d'export que les actions de l'interface complète ; le lien est ouvert avec
 * target="_blank" et le pont natif enregistre puis partage le fichier.
 */
class ExportController extends Controller
{
    public function detteAgent(Agent $agent, string $format)
    {
        abort_unless(request()->user()->can('view_remboursements'), 403);
        Gate::authorize('view', $agent);
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);

        $summary = AgentDebtDetailPage::summaryFor($agent->loadMissing('solde'));
        $history = AgentHistory::all($agent);
        $name = 'dette_'.Str::slug($agent->name).'_'.now()->format('Y-m-d_His').'.'.$format;

        if ($format === 'xlsx') {
            return Excel::download(new DettesExport($agent, $summary, $history), $name);
        }

        $pdf = Pdf::loadView('exports.dette-agent-pdf', [
            'agent' => $agent,
            'summary' => $summary,
            'history' => $history,
            'generatedAt' => now(),
            'generatedBy' => request()->user()->name ?? 'Système',
        ])->setPaper('a4', 'portrait');

        return response()->streamDownload(fn () => print ($pdf->output()), $name, ['Content-Type' => 'application/pdf']);
    }

    /** Commissions globales de la période (week | month | all). */
    public function commissions(string $format)
    {
        abort_unless(request()->user()->can('export_commissions'), 403);
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);

        [$from, $to, $label] = match (request('p')) {
            'week' => [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString(), 'Cette semaine'],
            'all' => [null, null, 'Tout'],
            default => [now()->startOfMonth()->toDateString(), now()->toDateString(), 'Ce mois'],
        };
        $name = 'commissions_'.now()->format('Y-m-d_His').'.'.$format;

        if ($format === 'xlsx') {
            return Excel::download(new CommissionsExport(null, $from, $to), $name);
        }

        $commissions = Commission::query()->with('agent')
            ->when($from, fn ($q) => $q->whereDate('date_commission', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date_commission', '<=', $to))
            ->orderByDesc('date_commission')->get();

        $pdf = Pdf::loadView('exports.commissions-pdf', [
            'commissions' => $commissions,
            'filters' => ['agent' => 'Tous', 'start_date' => $from ?? 'Début', 'end_date' => $to ?? now()->format('d/m/Y'), 'period' => $label],
            'generatedAt' => now(),
            'generatedBy' => request()->user()->name ?? 'Système',
        ])->setPaper('a4', 'portrait');

        return response()->streamDownload(fn () => print ($pdf->output()), $name, ['Content-Type' => 'application/pdf']);
    }

    /**
     * Rapports de l'écran « Rapports et exports » : ventes, remboursements,
     * situation des agents. Mêmes classes d'export et mêmes vues PDF que l'interface complète.
     */
    public function report(string $type, string $format)
    {
        abort_unless(request()->user()->can('export_reports'), 403);
        abort_unless(in_array($type, ['transactions', 'remboursements', 'agents'], true) && in_array($format, ['pdf', 'xlsx'], true), 404);
        abort_if($type === 'remboursements' && $format === 'pdf', 404);

        $q = request()->validate([
            'agent' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'type' => ['nullable', 'in:distribution,retour'],
            'method' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'in:active,inactive,suspended'],
            'debt' => ['nullable', 'boolean'],
        ]);
        $name = $type.'_'.now()->format('Y-m-d_His').'.'.$format;

        if ($format === 'xlsx') {
            $export = match ($type) {
                'transactions' => new UvTransactionsExport($q['agent'] ?? null, $q['from'] ?? null, $q['to'] ?? null, $q['type'] ?? null),
                'remboursements' => new RemboursementsExport($q['agent'] ?? null, $q['from'] ?? null, $q['to'] ?? null, $q['method'] ?? null),
                default => new AgentSituationExport($q['status'] ?? null, (bool) ($q['debt'] ?? false)),
            };

            return Excel::download($export, $name);
        }

        if ($type === 'transactions') {
            $transactions = UvTransaction::query()->with(['agent', 'fournisseur'])
                ->when($q['agent'] ?? null, fn ($x, $v) => $x->where('agent_id', $v))
                ->when($q['from'] ?? null, fn ($x, $v) => $x->whereDate('transaction_date', '>=', $v))
                ->when($q['to'] ?? null, fn ($x, $v) => $x->whereDate('transaction_date', '<=', $v))
                ->when($q['type'] ?? null, fn ($x, $v) => $x->where('transaction_type', $v))
                ->orderByDesc('transaction_date')->get();

            $pdf = Pdf::loadView('exports.transactions-pdf', [
                'transactions' => $transactions,
                'agent' => ($q['agent'] ?? null) ? Agent::find($q['agent']) : null,
                'startDate' => $q['from'] ?? null,
                'endDate' => $q['to'] ?? null,
            ])->setPaper('a4', 'landscape');
        } else {
            $agents = Agent::query()
                ->when($q['status'] ?? null, fn ($x, $v) => $x->where('status', $v))
                ->when($q['debt'] ?? false, fn ($x) => $x->where('current_debt', '>', 0))
                ->orderBy('name')->get();

            $pdf = Pdf::loadView('exports.agents-situation-pdf', ['agents' => $agents])->setPaper('a4', 'landscape');
        }

        return response()->streamDownload(fn () => print ($pdf->output()), $name, ['Content-Type' => 'application/pdf']);
    }

    /**
     * Export d'une carte « Aujourd'hui / Cette semaine / Ce mois / Total cumulé » des écrans
     * Ventes, Remboursements et Approvisionnements : mêmes bornes et mêmes lignes que la carte
     * (voir PeriodStats), donc mêmes totaux.
     */
    public function period(string $module, string $period, string $format)
    {
        abort_unless(in_array($module, PeriodStats::MODULES, true) && array_key_exists($period, PeriodStats::PERIODS) && in_array($format, ['pdf', 'xlsx'], true), 404);

        $user = request()->user();
        abort_unless(match ($module) {
            'ventes' => $user->can('export_uv_transactions'),
            'remboursements' => $user->can('export_remboursements'),
            'approvisionnements' => $user->can('view_approvisionnements'),
        }, 403);

        $stats = app(PeriodStats::class);
        $range = $stats->range($module, $period);
        [$fromLabel, $toLabel] = $stats->rangeLabels($module, $period);
        $name = $module.'_'.$period.'_'.now()->format('Y-m-d_His').'.'.$format;
        $from = $range ? $range['start']->toDateString() : null;
        $to = $range ? $range['end']->toDateString() : null;

        if ($format === 'xlsx') {
            return Excel::download(match ($module) {
                'ventes' => new UvTransactionsExport(null, null, null, 'distribution', $range['start'] ?? null, $range['end'] ?? null, true),
                'remboursements' => new RemboursementsExport(null, $from, $to, null),
                'approvisionnements' => new ApprovisionnementExport($from, $to, null),
            }, $name);
        }

        $this->raisePdfLimits();

        $query = $stats->query($module, $period);
        $count = (clone $query)->count();
        $total = (float) (clone $query)->sum('amount');
        $cap = $this->pdfRowCap();

        $rows = $query->with(match ($module) {
            'ventes' => ['agent', 'fournisseur'],
            'remboursements' => ['agent'],
            'approvisionnements' => ['fournisseur'],
        })->orderByDesc(match ($module) {
            'ventes' => 'transaction_date',
            'remboursements' => 'payment_date',
            'approvisionnements' => 'date_approvisionnement',
        })->orderByDesc('id')->limit($cap)->get();

        $methods = $module === 'remboursements' ? PaymentMethod::pluck('name', 'code') : collect();
        $money = fn ($n) => number_format((float) $n, 0, ',', ' ');

        [$title, $columns, $lines] = match ($module) {
            'ventes' => [
                'Rapport des ventes UV',
                [['label' => 'Référence'], ['label' => 'Date'], ['label' => 'Agent'], ['label' => 'Fournisseur'], ['label' => 'Montant (FCFA)', 'right' => true]],
                $rows->map(fn ($t) => [$t->reference, $t->transaction_date?->format('d/m/Y H:i'), $t->agent?->name ?? '-', $t->fournisseur?->name ?? '-', $money($t->amount)]),
            ],
            'remboursements' => [
                'Rapport des remboursements',
                [['label' => 'Référence'], ['label' => 'Date'], ['label' => 'Agent'], ['label' => 'Mode'], ['label' => 'Montant (FCFA)', 'right' => true]],
                $rows->map(fn ($r) => [$r->reference, $r->payment_date?->format('d/m/Y H:i'), $r->agent?->name ?? '-', $methods[$r->payment_method] ?? $r->payment_method, $money($r->amount)]),
            ],
            'approvisionnements' => [
                'Rapport des approvisionnements',
                [['label' => 'Référence'], ['label' => 'Date'], ['label' => 'Fournisseur'], ['label' => 'Type'], ['label' => 'Montant (FCFA)', 'right' => true]],
                $rows->map(fn ($a) => [$a->reference, $a->date_approvisionnement?->format('d/m/Y H:i'), $a->fournisseur?->name ?? '-', $a->fournisseur?->typeLabel() ?? '-', $money($a->amount)]),
            ],
        };

        return $this->periodPdf($name, "$module / $period", $rows->count(), [
            'title' => $title,
            'columns' => $columns,
            'rows' => $lines->all(),
            'count' => $count,
            'total' => $money($total),
            'truncated' => $count > $rows->count() ? $rows->count() : null,
            'period' => PeriodStats::PERIODS[$period],
            'from' => $fromLabel,
            'to' => $toLabel,
            'generatedBy' => $user->name ?? 'Système',
        ]);
    }

    /**
     * Export des charges (Excel, CSV, PDF) : mêmes périodes, mêmes totaux et même classe d'export que la page
     * « Gestion des Charges » du bureau. Le PDF utilise le modèle léger (voir period()).
     */
    public function charges(string $period, string $format)
    {
        abort_unless(array_key_exists($period, ChargePeriods::LABELS) && in_array($format, ['pdf', 'xlsx', 'csv'], true), 404);
        abort_unless(request()->user()->can('export_charges'), 403);

        $summary = ChargePeriods::summary($period);
        $name = 'charges_'.$period.'_'.now()->format('Y-m-d_His').'.'.$format;
        $base = fn () => Charge::query()->forPeriod($summary['start'], $summary['end'])->orderByDesc('date_charge')->orderByDesc('id');

        if ($format !== 'pdf') {
            return Excel::download(
                new ChargesExport($summary, $base()->with(['category', 'creator'])->get()),
                $name,
                $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
            );
        }

        $this->raisePdfLimits();

        $count = $base()->count();
        $rows = $base()->with(['category', 'creator'])->limit($this->pdfRowCap())->get();
        $money = fn ($n) => number_format((float) $n, 0, ',', ' ');

        return $this->periodPdf($name, "charges / $period", $rows->count(), [
            'title' => 'Rapport des charges',
            'columns' => [['label' => 'Date'], ['label' => 'Libellé'], ['label' => 'Catégorie'], ['label' => 'Saisi par'], ['label' => 'Montant (FCFA)', 'right' => true]],
            'rows' => $rows->map(fn ($c) => [$c->date_charge?->format('d/m/Y'), $c->libelle, $c->category?->name ?? '-', $c->creator?->name ?? '-', $money($c->montant)])->all(),
            'count' => $count,
            'total' => $money($summary['charges']),
            'truncated' => $count > $rows->count() ? $rows->count() : null,
            'period' => ChargePeriods::LABELS[$period],
            'from' => $summary['start']->format('d/m/Y'),
            'to' => $summary['end']->format('d/m/Y'),
            'extra' => ['Total revenu' => $money($summary['revenue']).' FCFA', 'Total charges' => $money($summary['charges']).' FCFA', 'Revenu net' => $money($summary['net']).' FCFA'],
            'generatedBy' => request()->user()->name ?? 'Système',
        ]);
    }

    /**
     * Rend le PDF léger d'un export de période. En cas d'échec (mémoire du serveur…), journalise et répond
     * par un message clair (JSON) que le pont de l'appli affiche, plutôt qu'une page 500 muette.
     */
    private function periodPdf(string $name, string $context, int $listed, array $data)
    {
        try {
            $output = Pdf::loadView('exports.period-pdf', $data + ['generatedAt' => now()])->setPaper('a4', 'landscape')->output();
        } catch (\Throwable $e) {
            Log::error('Export PDF « '.$context.' » impossible', ['rows' => $listed, 'memory_limit' => ini_get('memory_limit'), 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Le PDF n\'a pas pu être généré (mémoire du serveur insuffisante). Utilisez l\'export Excel ou une période plus courte.'], 500);
        }

        return response()->streamDownload(fn () => print ($output), $name, ['Content-Type' => 'application/pdf']);
    }

    /**
     * DomPDF est gourmand : on tente de relever la mémoire et le temps alloués (ignoré si l'hébergeur
     * les verrouille), sans jamais les réduire.
     */
    private function raisePdfLimits(): void
    {
        PdfLimits::raise();
    }

    /** Lignes maximum d'un PDF : configurable, sinon déduit de la mémoire réellement disponible (~0,3 Mo/ligne). */
    private function pdfRowCap(): int
    {
        if ($configured = config('mobile.pdf_max_rows')) {
            return max(1, (int) $configured);
        }

        $limit = PdfLimits::bytes((string) ini_get('memory_limit'));

        if ($limit === -1) {
            return 500;
        }

        return (int) max(50, min(500, floor(($limit / 1048576 - 80) / 0.3)));
    }

    /** Téléchargement d'une sauvegarde (master / admin, archives .zip du dossier de sauvegarde uniquement). */
    public function sauvegarde()
    {
        abort_unless(request()->user()->isSuperAdmin(), 403); // Les sauvegardes contiennent les données de TOUS les tenants : réservées au super admin de plateforme.

        $path = config('app.name', 'UVMASTER CI').'/'.basename((string) request('name'));
        abort_unless(Str::endsWith($path, '.zip') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, basename($path));
    }

    /** Fiche PDF d'un agent (même vue que l'action « Fiche Agent PDF » de l'interface complète). */
    public function ficheAgent(Agent $agent)
    {
        abort_unless(request()->user()->can('export_agents') || request()->user()->can('view_agents'), 403);
        Gate::authorize('view', $agent);

        $pdf = Pdf::loadView('exports.agent-fiche-pdf', ['agent' => $agent->load(['transactions', 'remboursements'])])->setPaper('a4', 'portrait');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'fiche_agent_'.$agent->id.'_'.now()->format('Y-m-d').'.pdf',
            ['Content-Type' => 'application/pdf']
        );
    }

    /** PDF d'une journée de caisse (mêmes données que « Exporter PDF » de la page Caisse du jour). */
    public function caisseJour(string $date)
    {
        abort_unless(request()->user()->can('view_caisse'), 403);

        try {
            $day = Carbon::createFromFormat('Y-m-d', $date, CaissePeriodResolver::TIMEZONE)->startOfDay();
        } catch (\Throwable) {
            abort(404);
        }

        $service = app(CaisseService::class);
        $periods = app(CaissePeriodResolver::class);

        $pdf = Pdf::loadView('exports.caisse-jour-pdf', [
            'tenant' => app(TenantDocumentBranding::class)->tenantName(),
            'periode' => $day->format('d/m/Y').' 00h00 → '.$periods->endLabelMoment($day)->format('d/m/Y').' 23h59',
            'date' => $day,
            'totals' => $service->dayTotals($day),
            'mouvements' => $service->mouvementsQuery($day)->with(['categorie', 'user'])->orderBy('date_mouvement')->orderBy('id')->get(),
            'balances' => $service->dayRunningBalances($day),
            'categories' => $service->categoryBalances($day, $day),
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        return response()->streamDownload(fn () => print ($pdf->output()), 'caisse_'.$day->format('Y-m-d').'.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** PDF de l'historique de caisse sur les 14 derniers jours ou sur tout l'historique (?p=all). */
    public function caisseHistorique()
    {
        abort_unless(request()->user()->can('view_caisse'), 403);

        $service = app(CaisseService::class);
        $periods = app(CaissePeriodResolver::class);
        $to = $periods->currentDate();
        $from = request('p') === 'all' ? null : $to->copy()->subDays(13);
        $days = $service->daySummaries($from, $to);

        $entrees = $days->reduce(fn ($c, $d) => bcadd($c, $d['entrees'], 2), '0');
        $sorties = $days->reduce(fn ($c, $d) => bcadd($c, $d['sorties'], 2), '0');

        $pdf = Pdf::loadView('exports.caisse-historique-pdf', [
            'tenant' => app(TenantDocumentBranding::class)->tenantName(),
            'periode' => $from ? 'Du '.$from->format('d/m/Y').' au '.$to->format('d/m/Y') : 'Toutes les journées',
            'days' => $days,
            'totalEntrees' => $entrees,
            'totalSorties' => $sorties,
            'solde' => bcsub($entrees, $sorties, 2),
            'categories' => $service->categoryBalances($from, $to),
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        return response()->streamDownload(fn () => print ($pdf->output()), 'caisse_historique_'.now()->format('Y-m-d_His').'.pdf', ['Content-Type' => 'application/pdf']);
    }
}
