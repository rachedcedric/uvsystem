<?php

namespace App\Imports;

use App\Models\Agent;
use App\Models\Fournisseur;
use App\Models\UvTransaction;
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

class UvTransactionsImport implements SkipsOnError, SkipsOnFailure, ToModel, WithBatchInserts, WithChunkReading, WithHeadingRow, WithValidation
{
    use SkipsErrors, SkipsFailures;

    private int $rowCount = 0;

    private int $successCount = 0;

    private bool $validateDebtCeiling;

    public function __construct(bool $validateDebtCeiling = true)
    {
        $this->validateDebtCeiling = $validateDebtCeiling;
    }

    /**
     * Transform row to UvTransaction model.
     */
    public function model(array $row): ?UvTransaction
    {
        $this->rowCount++;

        // Find agent by phone or ID
        $agent = $this->findAgent($row);
        if (! $agent) {
            return null;
        }

        // Get amount from row
        $amount = $row['montant'] ?? $row['amount'] ?? 0;

        // Validate debt ceiling if enabled
        if ($this->validateDebtCeiling && $agent->plafond_dette > 0) {
            $newDebt = $agent->current_debt + $amount;
            if ($newDebt > $agent->plafond_dette) {
                return null; // Skip this row - would exceed ceiling
            }
        }

        // Find fournisseur if provided
        $fournisseurId = null;
        if (! empty($row['fournisseur_nom'])) {
            $fournisseur = Fournisseur::where('nom', 'like', '%'.$row['fournisseur_nom'].'%')->first();
            $fournisseurId = $fournisseur?->id;
        }

        $this->successCount++;

        return new UvTransaction([
            'agent_id' => $agent->id,
            'fournisseur_id' => $fournisseurId,
            'transaction_type' => $row['type'] ?? 'distribution',
            'amount' => $amount,
            'transaction_date' => $this->parseDate($row['date_transaction'] ?? null),
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
     * Parse date from various formats.
     */
    private function parseDate($date): Carbon
    {
        if (empty($date)) {
            return now();
        }

        try {
            // Excel serial date
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
            'montant' => 'required_without:amount|numeric|min:0',
            'amount' => 'required_without:montant|numeric|min:0',
            'type' => 'nullable|in:distribution,retour',
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            'montant.required_without' => 'Le montant est obligatoire',
            'amount.required_without' => 'Le montant est obligatoire',
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
