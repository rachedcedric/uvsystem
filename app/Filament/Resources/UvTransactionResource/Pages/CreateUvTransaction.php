<?php

namespace App\Filament\Resources\UvTransactionResource\Pages;

use App\Filament\Resources\UvTransactionResource;
use App\Models\Agent;
use App\Models\Fournisseur;
use App\Models\UvTransaction;
use App\Services\MasterSoldeService;
use App\Services\UvDistributionService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateUvTransaction extends CreateRecord
{
    protected static string $resource = UvTransactionResource::class;

    /**
     * Le bouton "Créer et créer un autre" natif de Filament devient le seul
     * comportement : chaque vente reste sur cette page (voir create()), donc
     * un second bouton dupliquant ce comportement serait redondant.
     */
    protected static bool $canCreateAnother = false;

    private const HISTORY_PER_PAGE = 10;

    public int $historyPage = 1;

    // Doit être public : Livewire ne persiste entre deux requêtes que les
    // propriétés publiques. Une propriété protégée serait réinitialisée à
    // chaque hydratation, faisant croire à un changement d'agent en
    // permanence et bloquant la pagination.
    public ?int $lastHistoryAgentId = null;

    /**
     * Chaque vente créée avec succès doit garder l'utilisateur sur cette
     * page (agent conservé, historique et totaux actualisés), plutôt que de
     * rediriger vers la liste comme le ferait un "create" standard.
     */
    public function create(bool $another = false): void
    {
        parent::create(another: true);
    }

    /**
     * Reproduit le remplissage standard du formulaire, mais conserve l'agent
     * et le fournisseur sélectionnés d'une vente à l'autre (create() forçant
     * toujours le mode "créer un autre").
     */
    protected function fillForm(): void
    {
        $preservedAgentId = $this->data['agent_id'] ?? null;
        $preservedFournisseurId = $this->data['fournisseur_id'] ?? null;

        parent::fillForm();

        if ($preservedAgentId) {
            $this->form->fill([
                ...(array) $this->form->getRawState(),
                'agent_id' => $preservedAgentId,
                'fournisseur_id' => $preservedFournisseurId,
            ]);
        }

        $this->historyPage = 1;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Pré-validation "rapide" pour un retour utilisateur immédiat. La
        // vérification qui fait réellement foi (verrouillée, atomique) a lieu
        // dans UvDistributionService::distribute() lors de la création.
        if (($data['transaction_type'] ?? null) === 'distribution') {
            $masterService = new MasterSoldeService;

            try {
                $masterService->validateDistribution((float) $data['amount']);
            } catch (\Exception $e) {
                Notification::make()
                    ->danger()
                    ->title('Distribution impossible')
                    ->body($e->getMessage())
                    ->persistent()
                    ->send();

                $this->halt();
            }
        }

        return $data;
    }

    /**
     * Toute vente/retour doit être créé(e) via UvDistributionService, seule
     * source de vérité qui vérifie ET débite/recrédite le Solde UV
     * Disponible de façon atomique (verrou + vérification + création +
     * commit, ou rollback complet en cas d'échec).
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            $agent = Agent::findOrFail($data['agent_id']);
            $fournisseur = isset($data['fournisseur_id']) ? Fournisseur::find($data['fournisseur_id']) : null;
            $service = new UvDistributionService;

            if ($data['transaction_type'] === 'retour') {
                return $service->processReturn(
                    $agent,
                    (float) $data['amount'],
                    $data['transaction_date'] ?? null,
                    $data['notes'] ?? null,
                );
            }

            return $service->distribute(
                $agent,
                (float) $data['amount'],
                $fournisseur,
                $data['transaction_date'] ?? null,
                $data['notes'] ?? null,
            );
        } catch (Halt $e) {
            throw $e;
        } catch (ValidationException $e) {
            Notification::make()
                ->danger()
                ->title('Validation échouée')
                ->body(collect($e->errors())->flatten()->implode(' '))
                ->persistent()
                ->send();

            $this->halt();
        } catch (\Exception $e) {
            Notification::make()
                ->danger()
                ->title('Transaction impossible')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function afterCreate(): void
    {
        // Vérifier et alerter si solde bas
        $masterService = new MasterSoldeService;
        $masterService->checkAndAlertLowBalance();

        // La nouvelle vente doit apparaître en tête de l'historique.
        $this->historyPage = 1;
    }

    public function getFooter(): ?View
    {
        // On passe explicitement l'instance de la page : à l'intérieur d'une
        // vue rendue via getFooter() (contrairement à la vue principale du
        // composant Livewire), $this ne serait pas fiable.
        return view('filament.resources.uv-transaction.agent-sales-history', [
            'page' => $this,
        ]);
    }

    public function previousHistoryPage(): void
    {
        $this->historyPage = max(1, $this->historyPage - 1);
    }

    public function nextHistoryPage(): void
    {
        // getAgentSalesHistory() ramène historyPage à la dernière page valide
        // si on dépasse, donc pas besoin de connaître le total ici.
        $this->historyPage++;
    }

    public function gotoHistoryPage(int $page): void
    {
        $this->historyPage = max(1, $page);
    }

    /**
     * Historique + résumé des ventes UV du jour pour l'agent actuellement
     * sélectionné dans le formulaire. Ne casse jamais le formulaire principal
     * en cas d'échec (l'erreur est capturée et affichée dans sa propre
     * section).
     *
     * @return array{agentId: ?int, authorized: bool, error: bool, total: int, totalPages: int, totalAmount: float, items: array}
     */
    public function getAgentSalesHistory(): array
    {
        $rawAgentId = $this->data['agent_id'] ?? null;
        $agentId = $rawAgentId ? (int) $rawAgentId : null;

        if ($agentId !== $this->lastHistoryAgentId) {
            $this->historyPage = 1;
            $this->lastHistoryAgentId = $agentId;
        }

        $empty = [
            'agentId' => $agentId,
            'authorized' => true,
            'error' => false,
            'total' => 0,
            'totalPages' => 1,
            'totalAmount' => 0.0,
            'items' => [],
        ];

        if (! $agentId) {
            return $empty;
        }

        if (! Gate::allows('viewAny', UvTransaction::class)) {
            return [...$empty, 'authorized' => false];
        }

        try {
            $summaryQuery = UvTransaction::query()
                ->ventesAgentDuJour((int) $agentId)
                ->where('status', '!=', 'cancelled');

            $totalTransactions = (clone $summaryQuery)->count();
            $totalAmount = (float) (clone $summaryQuery)->sum('amount');

            $listQuery = UvTransaction::query()->ventesAgentDuJour((int) $agentId);
            $total = (clone $listQuery)->count();
            $totalPages = max(1, (int) ceil($total / self::HISTORY_PER_PAGE));

            if ($this->historyPage > $totalPages) {
                $this->historyPage = $totalPages;
            }

            $items = $listQuery
                ->with('agent:id,name')
                ->limit(self::HISTORY_PER_PAGE)
                ->offset(self::HISTORY_PER_PAGE * ($this->historyPage - 1))
                ->get();

            return [
                'agentId' => (int) $agentId,
                'authorized' => true,
                'error' => false,
                'total' => $total,
                'totalPages' => $totalPages,
                'totalAmount' => $totalAmount,
                'totalTransactions' => $totalTransactions,
                'items' => $items,
                'page' => $this->historyPage,
            ];
        } catch (\Throwable $e) {
            report($e);

            return [...$empty, 'error' => true];
        }
    }
}
