<?php

namespace App\Support\Subscriptions;

use Barryvdh\DomPDF\PDF;

/** PDF dont la génération est soumise aux fonctionnalités de l'offre (voir ExportGate). */
class GatedPdf extends PDF
{
    public function loadView(string $view, array $data = [], array $mergeData = [], ?string $encoding = null): self
    {
        ExportGate::checkPdfView($view);

        return parent::loadView($view, $data, $mergeData, $encoding);
    }
}
