<?php

namespace App\Http\Controllers;

use App\Models\Remboursement;
use Barryvdh\DomPDF\Facade\Pdf;

class RemboursementReceiptController extends Controller
{
    /**
     * Générer et télécharger le reçu de remboursement en PDF
     */
    public function download(Remboursement $remboursement)
    {
        $this->authorize('view', $remboursement);

        $remboursement->load(['agent', 'paymentMethod']);

        $data = [
            'remboursement' => $remboursement,
            'agent' => $remboursement->agent,
            'date' => $remboursement->payment_date->format('d/m/Y'),
            'numero_recu' => 'REC-'.str_pad($remboursement->id, 6, '0', STR_PAD_LEFT),
            'generated_at' => now()->format('d/m/Y H:i'),
        ];

        $pdf = Pdf::loadView('receipts.remboursement', $data);

        $filename = 'recu_remboursement_'.$data['numero_recu'].'.pdf';

        return $pdf->download($filename);
    }

    /**
     * Afficher le reçu dans le navigateur
     */
    public function show(Remboursement $remboursement)
    {
        $this->authorize('view', $remboursement);

        $remboursement->load(['agent', 'paymentMethod']);

        $data = [
            'remboursement' => $remboursement,
            'agent' => $remboursement->agent,
            'date' => $remboursement->payment_date->format('d/m/Y'),
            'numero_recu' => 'REC-'.str_pad($remboursement->id, 6, '0', STR_PAD_LEFT),
            'generated_at' => now()->format('d/m/Y H:i'),
        ];

        $pdf = Pdf::loadView('receipts.remboursement', $data);

        return $pdf->stream('recu_remboursement.pdf');
    }
}
