<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Gestion des Charges" : bloc résumé (période, total revenu, total
 * charges, revenu net) suivi du détail des charges de la période. Une seule
 * classe réutilisée pour Excel et CSV (writer différent au moment du
 * download), pas de logique de calcul propre — $summary est produit par
 * ChargeStatisticsService, jamais recalculé ici.
 */
class ChargesExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    protected int $detailHeaderRow;

    public function __construct(
        protected array $summary,
        protected Collection $charges
    ) {
        $this->detailHeaderRow = 7;
    }

    public function title(): string
    {
        return 'Charges';
    }

    public function array(): array
    {
        $rows = [
            ['Rapport des Charges - MODELEC CI'],
            ['Période', $this->summary['period_label']],
            ['Total Revenu (FCFA)', number_format($this->summary['revenue'], 0, ',', ' ')],
            ['Total Charges (FCFA)', number_format($this->summary['charges'], 0, ',', ' ')],
            ['Revenu net (FCFA)', number_format($this->summary['net'], 0, ',', ' ')],
            [],
            ['Date', 'Heure', 'Libellé', 'Catégorie', 'Montant (FCFA)', 'Description', 'Enregistré par'],
        ];

        foreach ($this->charges as $charge) {
            $rows[] = [
                $charge->date_charge?->format('d/m/Y'),
                $charge->heure_charge ? Carbon::parse($charge->heure_charge)->format('H:i') : '',
                $charge->libelle,
                $charge->category?->name ?? '',
                number_format($charge->montant, 0, ',', ' '),
                $charge->description ?? '',
                $charge->creator?->name ?? 'Système',
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            $this->detailHeaderRow => [
                'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'F59E0B'],
                ],
            ],
        ];
    }
}
