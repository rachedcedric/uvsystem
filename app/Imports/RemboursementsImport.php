<?php

namespace App\Imports;

use App\Models\Agent;
use App\Models\Remboursement;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class RemboursementsImport implements SkipsOnError, SkipsOnFailure, ToModel, WithBatchInserts, WithChunkReading, WithHeadingRow, WithValidation
{
    use SkipsErrors, SkipsFailures;

    private int $rowCount = 0;

    private int $successCount = 0;

    private bool $validateAmount;

    public function __construct(bool $validateAmount = true)
    {
        $this->validateAmount = $validateAmount;
    }

    /**
     * Transform row to Remboursement model.
     */
    public function model(array $row): ?Remboursement
    {
        $this->rowCount++;

        // Find agent
        $agent = $this->findAgent($row);
        if (! $agent) {
            return null;
        }

        $montant = (float) $row['montant'];

        // Validate amount doesn't exceed debt
        if ($this->validateAmount && $montant > $agent->current_debt) {
            return null; // Skip - amount exceeds debt
        }

        $this->successCount++;

        return new Remboursement([
            'agent_id' => $agent->id,
            'montant' => $montant,
            'mode_paiement' => $this->normalizePaymentMode($row['mode_paiement'] ?? 'cash'),
            'reference_paiement' => $row['reference_paiement'] ?? null,
            'date_remboursement' => $this->parseDate($row['date_remboursement'] ?? null),
            'status' => 'completed',
            'notes' => $row['notes'] ?? 'Importé via CSV',
        ]);
    }

    /**
     * Find agent by various identifiers.
     */
    private function findAgent(array $row): ?Agent
    {
        if (! empty($row['agent_id'])) {
            return Agent::find($row['agent_id']);
        }

        if (! empty($row['agent_telephone'])) {
            return Agent::where('telephone', $row['agent_telephone'])->first();
        }

        if (! empty($row['agent_nom']) && ! empty($row['agent_prenom'])) {
            return Agent::where('nom', $row['agent_nom'])
                ->where('prenom', $row['agent_prenom'])
                ->first();
        }

        return null;
    }

    /**
     * Normalize payment mode.
     */
    private function normalizePaymentMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return match (true) {
            str_contains($mode, 'virement') => 'virement',
            str_contains($mode, 'cheque') || str_contains($mode, 'chèque') => 'cheque',
            str_contains($mode, 'mobile') || str_contains($mode, 'om') || str_contains($mode, 'wave') => 'mobile_money',
            default => 'cash',
        };
    }

    /**
     * Parse date from various formats.
     */
    private function parseDate($date): Carbon
    {
        if (empty($date)) {
            return now();
        }

        try {
            if (is_numeric($date)) {
                return Carbon::createFromTimestamp(($date - 25569) * 86400);
            }

            return Carbon::parse($date);
        } catch (\Exception $e) {
            return now();
        }
    }

    public function rules(): array
    {
        return [
            'montant' => 'required|numeric|min:0.01',
            'mode_paiement' => 'nullable|string',
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            'montant.required' => 'Le montant est obligatoire',
            'montant.min' => 'Le montant doit être supérieur à 0',
        ];
    }

    public function batchSize(): int
    {
        return 50;
    }

    public function chunkSize(): int
    {
        return 50;
    }

    public function getRowCount(): int
    {
        return $this->rowCount;
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }
}
