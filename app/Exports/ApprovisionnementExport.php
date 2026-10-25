<?php

namespace App\Exports;

use App\Models\Approvisionnement;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ApprovisionnementExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    protected $dateFrom;

    protected $dateTo;

    protected $fournisseurId;

    public function __construct($dateFrom = null, $dateTo = null, $fournisseurId = null)
    {
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->fournisseurId = $fournisseurId;
    }

    public function collection(): Collection
    {
        $query = Approvisionnement::with(['fournisseur', 'user']);

        if ($this->dateFrom) {
            $query->whereDate('date_approvisionnement', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('date_approvisionnement', '<=', $this->dateTo);
        }

        if ($this->fournisseurId) {
            $query->where('fournisseur_id', $this->fournisseurId);
        }

        return $query->orderBy('date_approvisionnement', 'desc')->get();
    }

    public function headings(): array
    {
        return [
            'Référence',
            'Date',
            'Fournisseur',
            'Type Fournisseur',
            'Montant (FCFA)',
            'Acheteur',
            'Notes',
            'Créé le',
        ];
    }

    public function map($approvisionnement): array
    {
        return [
            $approvisionnement->reference,
            $approvisionnement->date_approvisionnement->format('d/m/Y'),
            $approvisionnement->fournisseur->name ?? 'N/A',
            $approvisionnement->fournisseur?->typeLabel() ?? 'N/A',
            number_format($approvisionnement->amount, 0, ',', ' '),
            $approvisionnement->user->name ?? 'N/A',
            $approvisionnement->notes ?? '',
            $approvisionnement->created_at->format('d/m/Y H:i'),
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E2E8F0']]],
        ];
    }

    public function title(): string
    {
        return 'Approvisionnements';
    }
}
