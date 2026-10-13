<?php

namespace App\Livewire\Mobile\Admin;

use App\Services\Mobile\DataImporter;
use App\Services\Subscriptions\SubscriptionEntitlements;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Excel as ExcelWriter;

/** Import CSV / Excel d'agents, de ventes ou de remboursements (mêmes classes d'import que l'interface complète). */
#[Layout('components.layouts.mobile')]
#[Title('Import de données')]
class Import extends Component
{
    use WithFileUploads;

    public const TYPES = ['agents' => 'Agents', 'transactions' => 'Ventes', 'remboursements' => 'Remboursements'];

    public const FORMATS = [
        'agents' => 'Colonnes : nom*, prenom*, telephone*, email, adresse, zone, commission_rate, plafond_dette, status, notes',
        'transactions' => 'Colonnes : agent_id ou agent_telephone ou (agent_nom + agent_prenom)*, montant*, type (distribution ou retour), fournisseur_nom, date_transaction, notes. Le solde UV et le plafond de dette de chaque agent sont toujours vérifiés.',
        'remboursements' => 'Colonnes : agent_id ou agent_telephone ou (agent_nom + agent_prenom)*, montant*, mode_paiement, reference_paiement, date_remboursement, notes. Un remboursement ne peut pas dépasser la dette de l\'agent.',
    ];

    public string $type = 'agents';

    public $file = null;

    /** @var array<string,mixed>|null */
    public ?array $results = null;

    public function mount(): void
    {
        abort_unless(self::allowed(), 403);
        app(SubscriptionEntitlements::class)->abortUnlessFeature('import_donnees');
    }

    public static function allowed(): bool
    {
        $user = auth()->user();

        return (bool) $user?->hasAnyRole(['master', 'admin']);
    }

    public function removeFile(): void
    {
        $this->file = null;
        $this->results = null;
    }

    public function import(): void
    {
        abort_unless(self::allowed(), 403);
        app(SubscriptionEntitlements::class)->abortUnlessFeature('import_donnees');

        $this->validate([
            'type' => ['required', 'in:agents,transactions,remboursements'],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ], [
            'file.required' => 'Choisissez un fichier CSV ou Excel.',
            'file.mimes' => 'Le fichier doit être un CSV, XLSX ou XLS.',
            'file.max' => 'Le fichier ne doit pas dépasser 10 Mo.',
        ]);

        $extension = strtolower($this->file->getClientOriginalExtension());
        $readerType = match ($extension) {
            'xlsx' => ExcelWriter::XLSX,
            'xls' => ExcelWriter::XLS,
            default => ExcelWriter::CSV,
        };

        try {
            $report = app(DataImporter::class)->import($this->type, $this->file->getRealPath(), $readerType);
            $errors = count($report['failures']);

            $this->results = [
                'rows' => $report['rows'],
                'success' => $report['success'],
                'skipped' => $report['skipped'],
                'errors' => $errors,
                'failures' => array_slice($report['failures'], 0, 15),
            ];

            activity()->causedBy(auth()->user())->withProperties([
                'type' => $this->type, 'rows' => $report['rows'], 'success' => $report['success'], 'errors' => $errors,
            ])->log('Import CSV effectué');

            $this->file = null;
            $this->dispatch('m-toast', message: $errors === 0 ? "{$report['success']} ligne(s) importée(s)." : "{$report['success']} réussie(s), {$errors} refusée(s).");
        } catch (\Throwable $e) {
            report($e);
            $this->results = ['fatal' => $e->getMessage()];
            $this->dispatch('m-toast', message: 'Import impossible : '.$e->getMessage(), type: 'error');
        }
    }

    public function render()
    {
        return view('livewire.mobile.admin.import');
    }
}
