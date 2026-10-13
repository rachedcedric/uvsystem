<?php

namespace App\Exports;

use App\Models\Remboursement;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RemboursementsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    use Exportable;

    protected ?string $agentId = null;

    protected ?string $startDate = null;

    protected ?string $endDate = null;

    protected ?string $paymentMethod = null;

    public function __construct(
        ?string $agentId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $paymentMethod = null
    ) {
        $this->agentId = $agentId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->paymentMethod = $paymentMethod;
    }

    public function query(): Builder
    {
        return Remboursement::query()
            ->with('agent')
            ->when($this->agentId, fn ($q) => $q->where('agent_id', $this->agentId))
            ->when($this->startDate, fn ($q) => $q->whereDate('payment_date', '>=', $this->startDate))
            ->when($this->endDate, fn ($q) => $q->whereDate('payment_date', '<=', $this->endDate))
            ->when($this->paymentMethod, fn ($q) => $q->where('payment_method', $this->paymentMethod))
            ->orderBy('payment_date', 'desc');
    }

    public function headings(): array
    {
        return [
            'Référence',
            'Date',
            'Agent',
            'Téléphone Agent',
            'Montant (FCFA)',
            'Mode de paiement',
            'Notes',
            'Créé le',
        ];
    }

    public function map($remboursement): array
    {
        $paymentMethods = [
            'cash' => 'Espèces',
            'mobile_money' => 'Mobile Money',
            'bank_transfer' => 'Virement bancaire',
            'cheque' => 'Chèque',
        ];

        return [
            $remboursement->reference,
            $remboursement->payment_date->format('d/m/Y'),
            $remboursement->agent?->name ?? 'N/A',
            $remboursement->agent?->phone ?? 'N/A',
            number_format($remboursement->amount, 0, ',', ' '),
            $paymentMethods[$remboursement->payment_method] ?? $remboursement->payment_method,
            $remboursement->notes ?? '',
            $remboursement->created_at->format('d/m/Y H:i'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '22C55E'],
                ],
            ],
        ];
    }
}
