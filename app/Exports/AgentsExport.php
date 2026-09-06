<?php

namespace App\Exports;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AgentsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    protected ?string $status;

    protected ?string $type;

    public function __construct(?string $status = null, ?string $type = null)
    {
        $this->status = $status;
        $this->type = $type;
    }

    public function query(): Builder
    {
        $query = Agent::query()->with(['transactions', 'remboursements']);

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->type) {
            $query->where('type', $this->type);
        }

        return $query->orderBy('name');
    }

    public function title(): string
    {
        return 'Agents';
    }

    public function headings(): array
    {
        return [
            'ID',
            'Nom',
            'Téléphone',
            'Email',
            'Adresse',
            'Zone',
            'Type',
            'Statut',
            'Taux Commission (%)',
            'Plafond Dette (FCFA)',
            'Dette Actuelle (FCFA)',
            'Ratio Dette (%)',
            'Date Création',
        ];
    }

    public function map($agent): array
    {
        $debtRatio = $agent->debt_limit > 0
            ? round(($agent->current_debt / $agent->debt_limit) * 100, 1)
            : 0;

        $typeLabels = [
            'standard' => 'Standard',
            'premium' => 'Premium',
            'vip' => 'VIP',
        ];

        $statusLabels = [
            'active' => 'Actif',
            'inactive' => 'Inactif',
            'suspended' => 'Suspendu',
            'blocked' => 'Bloqué',
        ];

        return [
            $agent->id,
            $agent->name,
            $agent->phone,
            $agent->email ?? '-',
            $agent->address ?? '-',
            $agent->zone ?? '-',
            $typeLabels[$agent->type] ?? $agent->type ?? 'Standard',
            $statusLabels[$agent->status] ?? $agent->status,
            $agent->commission_rate ?? 0,
            number_format($agent->debt_limit ?? 0, 0, ',', ' '),
            number_format($agent->current_debt ?? 0, 0, ',', ' '),
            $debtRatio.'%',
            $agent->created_at->format('d/m/Y'),
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
