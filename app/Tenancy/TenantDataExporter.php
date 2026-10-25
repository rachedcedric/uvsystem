<?php

namespace App\Tenancy;

use App\Models\Agent;
use App\Models\AgentSolde;
use App\Models\Approvisionnement;
use App\Models\CaisseCategorie;
use App\Models\CaisseMouvement;
use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Models\Commission;
use App\Models\Fournisseur;
use App\Models\MasterSolde;
use App\Models\PalierCommission;
use App\Models\PaymentMethod;
use App\Models\Remboursement;
use App\Models\RemboursementFournisseur;
use App\Models\SousDistributeur;
use App\Models\SousDistributeurRemboursement;
use App\Models\SousDistributeurSolde;
use App\Models\SousDistributeurTransaction;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UvTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Archive ZIP de TOUTES les données du tenant courant (un CSV par table + justificatifs + manifeste),
 * pour qu'un client récupère ses données sans jamais toucher à celles des autres.
 * Tout passe par les modèles scopés : le contenu est borné au tenant courant par construction.
 */
class TenantDataExporter
{
    /** @var array<string, class-string<Model>> */
    private const TABLES = [
        'agents' => Agent::class,
        'agent_soldes' => AgentSolde::class,
        'fournisseurs' => Fournisseur::class,
        'approvisionnements' => Approvisionnement::class,
        'uv_transactions' => UvTransaction::class,
        'remboursements' => Remboursement::class,
        'remboursements_fournisseurs' => RemboursementFournisseur::class,
        'commissions' => Commission::class,
        'paliers_commission' => PalierCommission::class,
        'sous_distributeurs' => SousDistributeur::class,
        'sous_distributeur_soldes' => SousDistributeurSolde::class,
        'sous_distributeur_transactions' => SousDistributeurTransaction::class,
        'sous_distributeur_remboursements' => SousDistributeurRemboursement::class,
        'payment_methods' => PaymentMethod::class,
        'caisse_categories' => CaisseCategorie::class,
        'caisse_mouvements' => CaisseMouvement::class,
        'charge_categories' => ChargeCategorie::class,
        'charges' => Charge::class,
        'master_soldes' => MasterSolde::class,
    ];

    /** Chemin du ZIP temporaire généré pour le tenant courant (à supprimer après envoi). */
    public function build(): string
    {
        $tenantId = TenantContext::requireId();
        $tenant = Tenant::findOrFail($tenantId);

        $zipPath = tempnam(sys_get_temp_dir(), 'tenant-export-');
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible de créer l\'archive.');
        }

        $tempFiles = [];
        $counts = [];

        foreach (self::TABLES as $name => $model) {
            $file = tempnam(sys_get_temp_dir(), 'csv-');
            $tempFiles[] = $file;
            $counts[$name] = $this->writeCsv($model, $file);
            $zip->addFile($file, "{$name}.csv");
        }

        $users = tempnam(sys_get_temp_dir(), 'csv-');
        $tempFiles[] = $users;
        $counts['users'] = $this->writeUsers($users);
        $zip->addFile($users, 'users.csv');

        $counts['justificatifs'] = $this->addFiles($zip, $tenant);

        $zip->addFromString('manifest.json', json_encode([
            'tenant' => $tenant->name,
            'exported_at' => now()->toIso8601String(),
            'rows' => $counts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $zip->close();
        array_map('unlink', $tempFiles);

        return $zipPath;
    }

    /** @param  class-string<Model>  $model */
    private function writeCsv(string $model, string $file): int
    {
        $handle = fopen($file, 'w');
        // Export complet : y compris les mouvements de caisse annulés (scope « actifs » levé, sans effet ailleurs).
        $query = $model::query()->withoutGlobalScope(CaisseMouvement::SCOPE_ACTIFS);

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        $header = false;
        $rows = 0;

        $query->orderBy((new $model)->getKeyName())->chunkById(1000, function ($chunk) use ($handle, &$header, &$rows) {
            foreach ($chunk as $row) {
                $attributes = $row->getAttributes();

                if (! $header) {
                    fputcsv($handle, array_keys($attributes));
                    $header = true;
                }

                fputcsv($handle, array_map(fn ($v) => $this->safe($v), array_values($attributes)));
                $rows++;
            }
        });

        fclose($handle);

        return $rows;
    }

    /** Comptes du tenant : jamais de mot de passe ni de matériel 2FA. */
    private function writeUsers(string $file): int
    {
        $handle = fopen($file, 'w');
        fputcsv($handle, ['id', 'name', 'email', 'roles', 'created_at']);
        $rows = 0;

        User::query()->with('roles:id,name')->orderBy('id')->each(function (User $user) use ($handle, &$rows) {
            fputcsv($handle, [$user->id, $this->safe($user->name), $this->safe($user->email), $user->roles->pluck('name')->implode('|'), $user->created_at]);
            $rows++;
        });

        fclose($handle);

        return $rows;
    }

    private function addFiles(ZipArchive $zip, Tenant $tenant): int
    {
        $disk = Storage::disk('local');
        $count = 0;

        foreach ($disk->allFiles($tenant->storagePath('charges')) as $path) {
            $zip->addFile($disk->path($path), 'justificatifs/'.basename($path));
            $count++;
        }

        return $count;
    }

    /** Neutralise l'injection de formules quand le CSV est ouvert dans Excel (=, +, -, @ en tête). */
    private function safe(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
