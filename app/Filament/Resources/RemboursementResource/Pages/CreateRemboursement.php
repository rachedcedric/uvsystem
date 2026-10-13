<?php

namespace App\Filament\Resources\RemboursementResource\Pages;

use App\Filament\Resources\RemboursementResource;
use App\Models\Agent;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use App\Services\RemboursementService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateRemboursement extends CreateRecord
{
    protected static string $resource = RemboursementResource::class;

    /**
     * Chaque remboursement reste sur cette page (voir create()) : un second
     * bouton "créer un autre" serait redondant.
     */
    protected static bool $canCreateAnother = false;

    private const HISTORY_PER_PAGE = 10;

    public int $historyPage = 1;

    // Doit être public pour persister entre deux requêtes Livewire (voir la
    // même remarque dans CreateUvTransaction).
    public ?int $lastHistoryAgentId = null;

    /**
     * Chaque remboursement créé avec succès doit garder l'utilisateur sur
     * cette page (agent conservé, historique et totaux actualisés).
     */
    public function create(bool $another = false): void
    {
        parent::create(another: true);
    }

    protected function fillForm(): void
    {
        $preservedAgentId = $this->data['agent_id'] ?? null;

        parent::fillForm();

        // Au tout premier chargement (rien à préserver encore), une
        // notification de dette peut présélectionner l'agent via
        // ?agent_id=XX (ex: /master/remboursements/create?agent_id=12). On
        // ne fait jamais confiance à cette valeur sans vérifier qu'il s'agit
        // bien d'un agent actif réel.
        $agentIdToApply = $preservedAgentId ?: $this->resolvePreselectedAgentId();

        if ($agentIdToApply) {
            $this->form->fill([
                ...(array) $this->form->getRawState(),
                'agent_id' => $agentIdToApply,
            ]);
        }

        $this->historyPage = 1;
    }

    protected function resolvePreselectedAgentId(): ?int
    {
        $agentId = request()->query('agent_id');

        if (! $agentId || ! is_numeric($agentId)) {
            return null;
        }

        return Agent::where('id', (int) $agentId)->where('status', 'active')->value('id');
    }

    /**
     * Tout remboursement doit être créé via RemboursementService, qui
     * valide (montant > 0, montant <= dette actuelle) au moment même de la
     * création plutôt que via une règle de formulaire basée sur un instantané
     * potentiellement obsolète (_current_debt capturé à la sélection de
     * l'agent).
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            $agent = Agent::findOrFail($data['agent_id']);

            return app(RemboursementService::class)->process(
                $agent,
                (float) $data['amount'],
                $data['payment_method'] ?? 'cash',
                $data['payment_date'] ?? null,
                $data['notes'] ?? null,
                $data['reference'] ?? null,
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
                ->title('Remboursement impossible')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function afterCreate(): void
    {
        // Le nouveau remboursement doit apparaître en tête de l'historique.
        $this->historyPage = 1;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Remboursement enregistré avec succès.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToDettes')
                ->label('← Retour aux Dettes')
                ->color('gray')
                ->url('/master/dettes'),
        ];
    }

    public function getFooter(): ?View
    {
        return view('filament.resources.remboursement.agent-daily-history', [
            'page' => $this,
        ]);
    }

    public function previousHistoryPage(): void
    {
        $this->historyPage = max(1, $this->historyPage - 1);
    }

    public function nextHistoryPage(): void
    {
        $this->historyPage++;
    }

    public function gotoHistoryPage(int $page): void
    {
        $this->historyPage = max(1, $page);
    }

    /**
     * Relance simplement le calcul (bouton "Réessayer" de l'état d'erreur) :
     * toute action Livewire déclenche déjà un nouveau rendu, donc un nouvel
     * appel à getAgentDailyHistory().
     */
    public function retryHistoryLoad(): void
    {
        //
    }

    /**
     * Historique + résumé des opérations (Remboursements & Dettes) du jour
     * pour l'agent sélectionné. Deux sources bien distinctes, jamais
     * confondues :
     *  - "Dette" = UvTransaction de type distribution (augmente la dette),
     *    exactement la même donnée que l'historique des ventes UV.
     *  - "Remboursement" = table remboursements (réduit la dette).
     * Le "reste dû" affiché par ligne est reconstitué avec la même formule
     * que Agent::recalculateDebt(), jamais une valeur inventée.
     *
     * @return array{agentId: ?int, authorized: bool, error: bool, total: int, totalPages: int, page: int, items: Collection, summary: array}
     */
    public function getAgentDailyHistory(): array
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
            'page' => 1,
            'items' => collect(),
            'summary' => [
                'totalRefunds' => 0.0,
                'totalDebtsCreated' => 0.0,
                'remainingDebt' => 0.0,
                'transactionsCount' => 0,
            ],
        ];

        if (! $agentId) {
            return $empty;
        }

        if (! Gate::allows('viewAny', Remboursement::class) || ! Gate::allows('viewAny', UvTransaction::class)) {
            return [...$empty, 'authorized' => false];
        }

        try {
            $agent = Agent::find($agentId);

            if (! $agent) {
                return [...$empty, 'error' => true];
            }

            $remboursements = Remboursement::query()->agentDuJour($agentId)->get();
            $dettes = UvTransaction::query()->ventesAgentDuJour($agentId)->get();

            $totalRefunds = (float) $remboursements->where('status', '!=', 'cancelled')->sum('amount');
            $totalDebtsCreated = (float) $dettes->where('status', '!=', 'cancelled')->sum('amount');

            // Dette au tout début de la journée, pour reconstituer le "reste
            // dû" après chaque opération en cumulant les mouvements réels
            // (même formule que Agent::recalculateDebt(), jamais filtrée par
            // statut : ce doit être la même vérité que current_debt).
            $debtBeforeToday = $agent->debtAsOf(Carbon::today()->subSecond());

            // Deux opérations peuvent partager la même seconde (les colonnes
            // date n'ont pas de précision infra-seconde) : l'id sert de
            // départage déterministe pour garder un ordre chronologique
            // stable plutôt qu'arbitraire.
            $merged = collect()
                ->concat($remboursements->map(fn (Remboursement $r) => [
                    'type' => 'remboursement',
                    'model' => $r,
                    'time' => $r->payment_date,
                    'id' => $r->id,
                    'delta' => -1 * (float) $r->amount,
                ]))
                ->concat($dettes->map(fn (UvTransaction $d) => [
                    'type' => 'dette',
                    'model' => $d,
                    'time' => $d->transaction_date,
                    'id' => $d->id,
                    'delta' => (float) $d->amount,
                ]))
                ->sortBy([
                    ['time', 'asc'],
                    ['id', 'asc'],
                ])
                ->values();

            // La dette étant un pool global (aucun remboursement n'est
            // rattaché à une vente précise dans le schéma), le statut d'une
            // ligne "Dette" (soldée / en cours) reflète l'état actuel réel
            // de l'agent (current_debt), identique sur toutes ses lignes du
            // jour — jamais une valeur reconstituée qui suggérerait un
            // rattachement precis qui n'existe pas. "Reste dû" par ligne,
            // en revanche, retrace fidèlement l'évolution réelle du solde
            // cumulé au fil des opérations de la journée.
            $currentDebt = (float) $agent->current_debt;
            $running = $debtBeforeToday;
            $merged = $merged->map(function (array $entry) use (&$running, $currentDebt) {
                $running += $entry['delta'];
                $entry['resteDuApres'] = $running;
                $entry['detteStatus'] = $currentDebt <= 0 ? 'soldee' : 'en_cours';

                return $entry;
            })->sortBy([
                ['time', 'desc'],
                ['id', 'desc'],
            ])->values();

            $total = $merged->count();
            $totalPages = max(1, (int) ceil($total / self::HISTORY_PER_PAGE));

            if ($this->historyPage > $totalPages) {
                $this->historyPage = $totalPages;
            }

            $items = $merged->slice(self::HISTORY_PER_PAGE * ($this->historyPage - 1), self::HISTORY_PER_PAGE)->values();

            return [
                'agentId' => $agentId,
                'authorized' => true,
                'error' => false,
                'total' => $total,
                'totalPages' => $totalPages,
                'page' => $this->historyPage,
                'items' => $items,
                'summary' => [
                    'totalRefunds' => $totalRefunds,
                    'totalDebtsCreated' => $totalDebtsCreated,
                    'remainingDebt' => (float) $agent->current_debt,
                    'transactionsCount' => $total,
                ],
            ];
        } catch (\Throwable $e) {
            report($e);

            return [...$empty, 'error' => true];
        }
    }
}
