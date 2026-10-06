<?php

namespace App\Exports;

use App\Models\UvTransaction;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class UvTransactionsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    use Exportable;

    protected ?string $agentId = null;

    protected ?string $startDate = null;

    protected ?string $endDate = null;

    protected ?string $transactionType = null;

    /** Bornes horodatées exactes (jour métier) : prioritaires sur les dates calendaires. */
    protected ?\Carbon\Carbon $rangeStart = null;

    protected ?\Carbon\Carbon $rangeEnd = null;

    protected bool $excludeCancelled = false;

    public function __construct(
        ?string $agentId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $transactionType = null,
        ?\Carbon\Carbon $rangeStart = null,
        ?\Carbon\Carbon $rangeEnd = null,
        bool $excludeCancelled = false,
    ) {
        $this->agentId = $agentId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->transactionType = $transactionType;
        $this->rangeStart = $rangeStart;
        $this->rangeEnd = $rangeEnd;
        $this->excludeCancelled = $excludeCancelled;
    }

    public function query(): Builder
    {
        return UvTransaction::query()
            ->with(['agent', 'fournisseur'])
            ->when($this->agentId, fn ($q) => $q->where('agent_id', $this->agentId))
            ->when($this->startDate, fn ($q) => $q->whereDate('transaction_date', '>=', $this->startDate))
            ->when($this->endDate, fn ($q) => $q->whereDate('transaction_date', '<=', $this->endDate))
            ->when($this->transactionType, fn ($q) => $q->where('transaction_type', $this->transactionType))
            ->when($this->rangeStart && $this->rangeEnd, fn ($q) => $q->whereBetween('transaction_date', [$this->rangeStart, $this->rangeEnd]))
            ->when($this->excludeCancelled, fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->orderBy('transaction_date', 'desc');
    }

    public function headings(): array
    {
        return [
            'Référence',
            'Date',
            'Agent',
            'Téléphone Agent',
            'Fournisseur',
            'Type',
            'Montant (FCFA)',
            'Créé le',
        ];
    }

    public function map($transaction): array
    {
        return [
            $transaction->reference,
            $transaction->transaction_date->format('d/m/Y'),
            $transaction->agent?->name ?? 'N/A',
            $transaction->agent?->phone ?? 'N/A',
            $transaction->fournisseur?->name ?? 'N/A',
            $transaction->transaction_type === 'distribution' ? 'Distribution' : 'Retour',
            number_format($transaction->amount, 0, ',', ' '),
            $transaction->created_at->format('d/m/Y H:i'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '3B82F6'],
                ],
            ],
        ];
    }
}
