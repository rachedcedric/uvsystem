<?php

namespace App\Http\Controllers;

use App\Exports\ReportCategoryExport;
use App\Services\Reports\ClotureHistoryService;
use App\Services\Reports\DateRange;
use App\Services\Reports\DebtHistoryService;
use App\Services\Reports\ReportCatalog;
use App\Services\TenantDocumentBranding;
use App\Support\PdfLimits;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Export PDF / Excel d'une catégorie de « Rapports et exports », pour l'interface Web et l'application mobile :
 * mêmes lignes et mêmes totaux que l'écran, pour la période (dates libres, fin incluse) et les filtres choisis.
 * Les données viennent toujours de l'entreprise de l'utilisateur connecté (scope d'entreprise) ; la catégorie
 * vérifie les permissions et l'offre (exports_pdf / exports_excel) est appliquée par GatedPdf / GatedExcel.
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, ReportCatalog $catalog, string $category, string $format)
    {
        $report = $catalog->get($category);
        abort_unless($report && in_array($format, ['pdf', 'xlsx'], true), 404);
        abort_unless($report->allows($request->user()), 403);

        try {
            $range = DateRange::fromInput($request->only(['periode', 'du', 'au']));
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->implode(' ')], 422);
        }

        $filters = array_filter([
            'q' => trim((string) $request->query('q', '')),
            'type' => array_key_exists((string) $request->query('type'), DebtHistoryService::TYPES) ? (string) $request->query('type') : null,
            'statut' => array_key_exists((string) $request->query('statut'), DebtHistoryService::STATUTS) ? (string) $request->query('statut') : null,
            'nature' => array_key_exists((string) $request->query('nature'), ClotureHistoryService::NATURES) ? (string) $request->query('nature') : null,
            'jours' => array_key_exists((string) $request->query('jours'), ClotureHistoryService::JOURS) ? (string) $request->query('jours') : null,
        ], fn ($v) => $v !== null && $v !== '');

        $name = str_replace('_', '-', $report->key()).'_'.$range->from->format('Y-m-d').'_'.$range->to->format('Y-m-d').'.'.$format;

        if ($format === 'xlsx') {
            return Excel::download(new ReportCategoryExport($report, $range, $filters), $name);
        }

        PdfLimits::raise();
        $cap = max(50, (int) (config('mobile.pdf_max_rows') ?: 500));
        $summary = $report->summary($range, $filters);
        $rows = $report->rows($range, $filters, $cap);

        try {
            $output = Pdf::loadView('exports.rapport-pdf', [
                'company' => app(TenantDocumentBranding::class)->tenantName(),
                'title' => $report->label(),
                'dated' => $report->dated(),
                'period' => $range->label(),
                'from' => $range->fromLabel(),
                'to' => $range->toLabel(),
                'columns' => $report->columns(),
                'rows' => $rows,
                'count' => $summary['count'],
                'total' => number_format($summary['total'], 0, ',', ' '),
                'totalLabel' => $summary['total_label'],
                'extra' => $summary['extra'] ?? [],
                // footer null : pas de ligne de total (historique des clôtures, où une dette se répète chaque jour).
                'footer' => array_key_exists('footer', $summary) ? $summary['footer'] : [array_key_last($report->columns()) => $summary['total']],
                'truncated' => $summary['count'] > $rows->count() ? $rows->count() : null,
                'filtersLabel' => collect([
                    isset($filters['q']) ? 'recherche « '.$filters['q'].' »' : null,
                    isset($filters['type']) ? DebtHistoryService::TYPES[$filters['type']] : null,
                    isset($filters['statut']) ? DebtHistoryService::STATUTS[$filters['statut']] : null,
                    isset($filters['nature']) ? ClotureHistoryService::NATURES[$filters['nature']] : null,
                    isset($filters['jours']) ? ClotureHistoryService::JOURS[$filters['jours']] : null,
                ])->filter()->implode(' · '),
                'generatedAt' => now(),
                'generatedBy' => $request->user()->name ?? 'Système',
            ])->setPaper('a4', 'landscape')->output();
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Export PDF « '.$report->key().' » impossible', ['rows' => $rows->count(), 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Le PDF n\'a pas pu être généré (mémoire du serveur insuffisante). Utilisez l\'export Excel ou une période plus courte.'], 500);
        }

        return response()->streamDownload(fn () => print ($output), $name, ['Content-Type' => 'application/pdf']);
    }
}
