<?php

namespace App\Support\Subscriptions;

use App\Services\TenantDocumentBranding;
use Barryvdh\DomPDF\PDF;

/** PDF dont la génération est soumise aux fonctionnalités de l'offre (voir ExportGate). */
class GatedPdf extends PDF
{
    public function loadView(string $view, array $data = [], array $mergeData = [], ?string $encoding = null): self
    {
        ExportGate::checkPdfView($view);

        $html = $this->view->make($view, $data, $mergeData)->render();
        $branding = app(TenantDocumentBranding::class)->current();

        if ($branding !== null) {
            $footer = $this->view->make('exports.partials.tenant-document-branding', compact('branding'))->render();
            $html = str_contains($html, '</body>')
                ? preg_replace_callback('/<\/body>/i', fn (): string => $footer.'</body>', $html, 1)
                : $html.$footer;
        }

        return $this->loadHTML($html, $encoding);
    }
}
