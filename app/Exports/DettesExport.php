<?php

namespace App\Exports;

use App\Models\Agent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export Excel de la dette d'un agent : résumé (dette du jour / total
 * dette / remboursé / reste à payer) puis historique complet — toutes les
 * lignes de l'agent, jamais seulement la page affichée à l'écran. Les
 * montants sont écrits comme valeurs numériques (pas de chaîne formatée),
 * pour rester exploitables dans Excel.
 */
class DettesExport implements FromArray, ShouldAutoSize, WithStyles
{
    private const HISTORY_HEADER_ROW = 10;

    public function __construct(
        protected Agent $agent,
        protected array $summary,
        protected Collection $history,
    ) {}

    public function array(): array
    {
        $rows = [
            ['Agent', $this->agent->name],
            ['Téléphone', $this->agent->phone ?? ''],
            ['Généré le', now()->format('d/m/Y H:i')],
            [],
            ['Dette du jour (FCFA)', (float) $this->summary['detteDuJour']],
            ['Total dette (FCFA)', (float) $this->summary['totalDette']],
            ['Remboursé (FCFA)', (float) $this->summary['rembourse']],
            ['Reste à payer (FCFA)', (float) $this->summary['resteAPayer']],
            [],
            ['Date et heure', 'Type', 'Dette (FCFA)', 'Remboursement (FCFA)', 'Solde avant (FCFA)', 'Solde après (FCFA)', 'Référence'],
        ];

        $typeLabels = ['distribution' => 'Vente', 'retour' => 'Retour', 'remboursement' => 'Remboursement'];

        foreach ($this->history as $row) {
            $rows[] = [
                Carbon::parse($row->date_operation)->format('d/m/Y H:i'),
                $typeLabels[$row->type] ?? $row->type,
                $row->amount !== null ? (float) $row->amount : null,
                $row->remboursement_amount !== null ? (float) $row->remboursement_amount : null,
                $row->solde_uv_avant !== null ? (float) $row->solde_uv_avant : null,
                $row->solde_uv_apres !== null ? (float) $row->solde_uv_apres : null,
                $row->reference,
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            self::HISTORY_HEADER_ROW => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'EF4444'],
                ],
            ],
        ];
    }
}
