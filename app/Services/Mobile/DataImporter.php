<?php

namespace App\Services\Mobile;

use App\Models\Agent;
use App\Models\Fournisseur;
use App\Services\RemboursementService;
use App\Services\ShortReferenceGenerator;
use App\Services\UvDistributionService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Import CSV / Excel d'agents, de ventes et de remboursements, ligne par ligne.
 *
 * Contrairement aux anciennes classes d'import (écrites pour un autre schéma),
 * chaque ligne passe par les services métier : solde UV disponible, plafond de
 * dette et reste à payer sont donc toujours contrôlés. Une ligne refusée
 * n'empêche pas les suivantes.
 */
class DataImporter
{
    /**
     * @return array{rows:int, success:int, skipped:int, failures:list<array{row:int,message:string}>}
     */
    public function import(string $type, string $path, string $readerType): array
    {
        $rows = Excel::toCollection(new class implements ToCollection, WithHeadingRow
        {
            public function collection(Collection $rows): void {}
        }, $path, null, $readerType)->first() ?? collect();

        $result = ['rows' => 0, 'success' => 0, 'skipped' => 0, 'failures' => []];

        foreach ($rows as $index => $row) {
            $row = collect($row)->map(fn ($v) => is_string($v) ? trim($v) : $v);
            if ($row->filter(fn ($v) => $v !== null && $v !== '')->isEmpty()) {
                continue; // ligne vide
            }

            $result['rows']++;
            $line = $index + 2; // en-tête en ligne 1

            try {
                $created = match ($type) {
                    'agents' => $this->agent($row),
                    'transactions' => $this->transaction($row),
                    default => $this->remboursement($row),
                };
                $created ? $result['success']++ : $result['skipped']++;
            } catch (ValidationException $e) {
                $result['failures'][] = ['row' => $line, 'message' => collect($e->errors())->flatten()->implode(' ')];
            } catch (\Throwable $e) {
                report($e);
                $result['failures'][] = ['row' => $line, 'message' => 'Erreur inattendue : '.$e->getMessage()];
            }
        }

        return $result;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['row' => $message]);
    }

    private function agent(Collection $row): bool
    {
        $nom = (string) ($row['nom'] ?? '');
        $prenom = (string) ($row['prenom'] ?? '');
        $phone = preg_replace('/\s+/', '', (string) ($row['telephone'] ?? ''));

        if ($nom === '' || $prenom === '') {
            $this->fail('Le nom et le prénom sont obligatoires.');
        }
        if (! preg_match('/^[0-9]{8,15}$/', $phone)) {
            $this->fail('Numéro de téléphone invalide (8 à 15 chiffres).');
        }
        if (Agent::withTrashed()->where('phone', $phone)->exists()) {
            return false; // doublon ignoré
        }

        $email = $row['email'] ?? null;
        if ($email && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('Adresse e-mail invalide.');
        }
        // Colonne absente ou cellule vide : aucune valeur imposée, le champ reste vide (null).
        $rate = $row['commission_rate'] ?? null;
        $rate = ($rate === null || trim((string) $rate) === '') ? null : $rate;
        if ($rate !== null && (! is_numeric($rate) || $rate < 0 || $rate > 100)) {
            $this->fail('Taux de commission invalide (0 à 100).');
        }
        $limit = $row['plafond_dette'] ?? null;
        $limit = ($limit === null || trim((string) $limit) === '') ? null : Money::toFloat($limit);
        $status = $row['status'] ?? 'active';
        if (! in_array($status, ['active', 'inactive', 'suspended'], true)) {
            $this->fail('Statut invalide (active, inactive ou suspended).');
        }

        Agent::create([
            'name' => trim($nom.' '.$prenom),
            'phone' => $phone,
            'email' => $email ?: null,
            'address' => $row['adresse'] ?? null,
            'zone' => $row['zone'] ?? null,
            'type' => 'standard',
            'debt_limit' => $limit,
            'commission_rate' => $rate === null ? null : (float) $rate,
            'status' => $status,
            'notes' => $row['notes'] ?? null,
        ]);

        return true;
    }

    private function findAgent(Collection $row): Agent
    {
        $agent = match (true) {
            filled($row['agent_id'] ?? null) => Agent::find($row['agent_id']),
            filled($row['agent_telephone'] ?? null) => Agent::where('phone', preg_replace('/\s+/', '', (string) $row['agent_telephone']))->first(),
            filled($row['agent_nom'] ?? null) && filled($row['agent_prenom'] ?? null) => Agent::where('name', trim($row['agent_nom'].' '.$row['agent_prenom']))->first(),
            default => null,
        };

        return $agent ?? $this->fail('Agent introuvable (agent_id, agent_telephone ou agent_nom + agent_prenom).');
    }

    private function date(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return (is_numeric($value) ? Carbon::createFromTimestamp(((float) $value - 25569) * 86400) : Carbon::parse($value))->toDateTimeString();
        } catch (\Throwable) {
            $this->fail('Date invalide : '.$value);
        }
    }

    private function amount(Collection $row, string ...$keys): float
    {
        foreach ($keys as $key) {
            if (filled($row[$key] ?? null)) {
                $amount = Money::toFloat($row[$key]);
                if ($amount > 0) {
                    return $amount;
                }
            }
        }

        $this->fail('Le montant est obligatoire et doit être supérieur à 0.');
    }

    private function transaction(Collection $row): bool
    {
        $agent = $this->findAgent($row);
        $amount = $this->amount($row, 'montant', 'amount');
        $date = $this->date($row['date_transaction'] ?? null);
        $notes = $row['notes'] ?? 'Importé via CSV';
        $service = new UvDistributionService;

        if (($row['type'] ?? 'distribution') === 'retour') {
            $service->processReturn($agent, $amount, $date, $notes);

            return true;
        }

        $fournisseur = filled($row['fournisseur_nom'] ?? null) ? Fournisseur::where('name', 'like', '%'.$row['fournisseur_nom'].'%')->first() : null;
        $service->distribute($agent, $amount, $fournisseur, $date, $notes);

        return true;
    }

    private function remboursement(Collection $row): bool
    {
        $agent = $this->findAgent($row);
        $amount = $this->amount($row, 'montant', 'amount');

        app(RemboursementService::class)->process(
            $agent,
            $amount,
            (string) ($row['mode_paiement'] ?? 'cash'),
            $this->date($row['date_remboursement'] ?? null),
            $row['notes'] ?? null,
            filled($row['reference_paiement'] ?? null) ? (string) $row['reference_paiement'] : app(ShortReferenceGenerator::class)->generate('R', 'remboursements'),
        );

        return true;
    }
}
