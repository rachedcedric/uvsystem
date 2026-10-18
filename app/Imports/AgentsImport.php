<?php

namespace App\Imports;

use App\Models\Agent;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class AgentsImport implements SkipsOnError, SkipsOnFailure, ToModel, WithBatchInserts, WithChunkReading, WithHeadingRow, WithValidation
{
    use SkipsErrors, SkipsFailures;

    private int $rowCount = 0;

    private array $importedIds = [];

    /**
     * Transform row to Agent model.
     */
    public function model(array $row): ?Agent
    {
        $this->rowCount++;

        // Skip if phone already exists
        if (Agent::where('telephone', $row['telephone'])->exists()) {
            return null;
        }

        $agent = new Agent([
            'nom' => $row['nom'],
            'prenom' => $row['prenom'],
            'telephone' => $row['telephone'],
            'email' => $row['email'] ?? null,
            'adresse' => $row['adresse'] ?? null,
            'zone' => $row['zone'] ?? null,
            // Aucune valeur imposée quand la colonne est absente : le champ reste vide (null).
            'commission_rate' => $row['commission_rate'] ?? null,
            'plafond_dette' => $row['plafond_dette'] ?? null,
            'status' => $row['status'] ?? 'active',
            'notes' => $row['notes'] ?? null,
        ]);

        return $agent;
    }

    /**
     * Validation rules for each row.
     */
    public function rules(): array
    {
        return [
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'telephone' => 'required|regex:/^[0-9]{8,15}$/',
            'email' => 'nullable|email',
            'zone' => 'nullable|string|max:100',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'plafond_dette' => 'nullable|numeric|min:0|max:999999999',
            'status' => 'nullable|in:active,inactive,suspended',
        ];
    }

    /**
     * Custom validation messages.
     */
    public function customValidationMessages(): array
    {
        return [
            'nom.required' => 'Le nom est obligatoire',
            'prenom.required' => 'Le prénom est obligatoire',
            'telephone.required' => 'Le téléphone est obligatoire',
            'telephone.regex' => 'Le numéro de téléphone est invalide (8-15 chiffres)',
            'email.email' => 'L\'email est invalide',
        ];
    }

    public function batchSize(): int
    {
        return 100;
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function getRowCount(): int
    {
        return $this->rowCount;
    }
}
