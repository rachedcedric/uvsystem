<?php

namespace App\Exports;

use App\Models\VenteUv;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class VentesUvExport implements FromCollection, WithColumnWidths, WithHeadings, WithMapping, WithStyles
{
    /**
     * @return Collection
     */
    public function collection()
    {
        return VenteUv::with(['agentPartenaire', 'user'])
            ->orderBy('date_vente', 'desc')
            ->get();
    }

    /**
     * Colonnes de l'export
     */
    public function headings(): array
    {
        return [
            'Date Vente',
            'Référence',
            'Agent Partenaire',
            'Quantité UV',
            'Prix Unitaire (FCFA)',
            'Montant Total (FCFA)',
            'Vendeur',
            'Notes',
        ];
    }

    /**
     * Mapper chaque ligne
     */
    public function map($vente): array
    {
        return [
            $vente->date_vente->format('d/m/Y'),
            $vente->reference,
            $vente->agentPartenaire?->nom_entreprise ?? 'N/A',
            number_format($vente->quantity_uv, 0, ',', ' '),
            number_format($vente->prix_unitaire ?? 0, 2, ',', ' '),
            number_format($vente->montant_total, 0, ',', ' '),
            $vente->user?->name ?? 'N/A',
            $vente->notes ?? '',
        ];
    }

    /**
     * Styles du tableau
     */
    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'size' => 12],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '2196F3'],
                ],
                'alignment' => ['horizontal' => 'center'],
            ],
        ];
    }

    /**
     * Largeurs des colonnes
     */
    public function columnWidths(): array
    {
        return [
            'A' => 12,  // Date
            'B' => 20,  // Référence
            'C' => 30,  // Agent
            'D' => 12,  // Quantité
            'E' => 18,  // Prix Unit
            'F' => 20,  // Montant
            'G' => 20,  // Vendeur
            'H' => 40,  // Notes
        ];
    }
}
