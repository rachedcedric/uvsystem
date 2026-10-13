<?php

namespace App\Exports;

use App\Models\Commission;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CommissionsExport implements FromQuery, ShouldAutoSize, WithColumnWidths, WithHeadings, WithMapping, WithStyles, WithTitle
{
    protected ?int $agentId;

    protected ?string $startDate;

    protected ?string $endDate;

    public function __construct(?int $agentId = null, ?string $startDate = null, ?string $endDate = null)
    {
        $this->agentId = $agentId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function query(): Builder
    {
        $query = Commission::query()->with(['agent']);

        if ($this->agentId) {
            $query->where('agent_id', $this->agentId);
        }
        if ($this->startDate) {
            $query->whereDate('date_commission', '>=', $this->startDate);
        }
        if ($this->endDate) {
            $query->whereDate('date_commission', '<=', $this->endDate);
        }

        return $query->orderBy('date_commission', 'desc');
    }

    public function title(): string
    {
        return 'Commissions';
    }

    /**
     * Colonnes de l'export
     */
    public function headings(): array
    {
        return [
            'Date',
            'Agent',
            'Téléphone Agent',
            'Volume Ventes (FCFA)',
            'Commission (FCFA)',
            'Palier Min (FCFA)',
            'Palier Max (FCFA)',
            'Notes',
        ];
    }

    /**
     * Mapper chaque ligne
     */
    public function map($commission): array
    {
        return [
            $commission->date_commission?->format('d/m/Y') ?? '-',
            $commission->agent?->name ?? 'N/A',
            $commission->agent?->phone ?? '-',
            number_format($commission->montant_vente, 0, ',', ' '),
            number_format($commission->montant_commission, 0, ',', ' '),
            number_format($commission->seuil_min, 0, ',', ' '),
            number_format($commission->seuil_max, 0, ',', ' '),
            $commission->notes ?? '',
        ];
    }

    /**
     * Styles du tableau
     */
    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'F59E0B'],
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
            'A' => 12,
            'B' => 25,
            'C' => 15,
            'D' => 18,
            'E' => 15,
            'F' => 15,
            'G' => 15,
            'H' => 30,
        ];
    }
}
