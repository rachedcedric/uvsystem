<?php

namespace App\Exports;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AgentSituationExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    public function __construct(
        protected ?string $status = null,
        protected bool $withDebtOnly = false,
        protected ?array $ids = null,
    ) {}

    public function query(): Builder
    {
        $query = Agent::query();

        if ($this->ids) {
            $query->whereIn('id', $this->ids);
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->withDebtOnly) {
            $query->where('current_debt', '>', 0);
        }

        return $query->orderBy('name');
    }

    public function headings(): array
    {
        return [
            'Nom',
            'Téléphone',
            'Statut',
            'Plafond (FCFA)',
            'Dette actuelle (FCFA)',
            'Crédit disponible (FCFA)',
            'Ratio dette (%)',
            'Nb Transactions',
            'Nb Remboursements',
            'Créé le',
        ];
    }

    public function map($agent): array
    {
        $creditDisponible = max(0, $agent->debt_limit - $agent->current_debt);
        $ratio = $agent->debt_limit > 0
            ? round(($agent->current_debt / $agent->debt_limit) * 100, 1)
            : 0;

        return [
            $agent->name,
            $agent->phone,
            match ($agent->status) {
                'active' => 'Actif',
                'inactive' => 'Inactif',
                'suspended' => 'Suspendu',
                default => $agent->status
            },
            $agent->debt_limit,
            $agent->current_debt,
            $creditDisponible,
            $ratio.'%',
            $agent->transactions()->count(),
            $agent->remboursements()->count(),
            $agent->created_at->format('d/m/Y'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '8B5CF6'],
                ],
            ],
        ];
    }
}
