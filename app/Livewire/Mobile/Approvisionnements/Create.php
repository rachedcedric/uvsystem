<?php

namespace App\Livewire\Mobile\Approvisionnements;

use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Services\ApprovisionnementService;
use App\Services\MasterSoldeService;
use App\Services\RetourAgentService;
use App\Support\Mobile\Fcfa;
use App\Support\Mobile\OperationDateTime;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Nouvel approvisionnement')]
class Create extends Component
{
    public ?int $fournisseurId = null;

    /** Agent qui rend ses UV, quand le fournisseur choisi est « Retour Agent ». */
    public ?int $agentId = null;

    public string $amount = '';

    public string $date = '';

    public string $time = '';

    public string $notes = '';

    public function mount(): void
    {
        Gate::authorize('create', Approvisionnement::class);

        ['date' => $this->date, 'time' => $this->time] = OperationDateTime::defaults();
    }

    #[Computed]
    public function fournisseurs()
    {
        // Entrée « Retour Agent » (créée par la migration ; rattrapage ici). Si l'écriture est refusée (accès restreint),
        // le formulaire reste utilisable sans elle.
        rescue(fn () => Fournisseur::retourAgent(), report: false);

        return Fournisseur::where('is_active', true)->selectionnables()->orderBy('name')->get(['id', 'name', 'type', 'agent_id']);
    }

    #[Computed]
    public function isRetour(): bool
    {
        return (bool) $this->fournisseurs->firstWhere('id', $this->fournisseurId)?->isRetourAgent();
    }

    #[Computed]
    public function agents()
    {
        return Agent::query()->orderBy('name')->get(['id', 'name', 'phone', 'current_debt']);
    }

    #[Computed]
    public function selectedAgent(): ?Agent
    {
        return $this->agentId ? $this->agents->firstWhere('id', $this->agentId) : null;
    }

    /** @return array{du_avant: float, du_apres: float}|null */
    #[Computed]
    public function impact(): ?array
    {
        return $this->selectedAgent ? app(RetourAgentService::class)->impact($this->selectedAgent, $this->numericAmount) : null;
    }

    public function updatedFournisseurId(): void
    {
        $this->agentId = null;
    }

    #[Computed]
    public function balance(): float
    {
        return app(MasterSoldeService::class)->getAvailableBalance();
    }

    #[Computed]
    public function numericAmount(): float
    {
        return Money::toFloat($this->amount);
    }

    public function save()
    {
        Gate::authorize('create', Approvisionnement::class);

        $this->validate([
            'fournisseurId' => ['required', 'integer'],
            'amount' => ['required'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'fournisseurId.required' => 'Choisissez un fournisseur.',
            'amount.required' => 'Saisissez un montant.',
            'date.before_or_equal' => 'La date ne peut pas être dans le futur.',
        ]);

        if ($this->numericAmount < 1) {
            throw ValidationException::withMessages(['amount' => 'Le montant doit être supérieur à 0.']);
        }

        if (! Fournisseur::where('id', $this->fournisseurId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['fournisseurId' => 'Ce fournisseur est introuvable ou inactif.']);
        }

        if ($this->isRetour) {
            return $this->saveRetour();
        }

        $appro = app(ApprovisionnementService::class)->create([
            'user_id' => auth()->id(),
            'fournisseur_id' => $this->fournisseurId,
            'amount' => $this->numericAmount,
            'date_approvisionnement' => OperationDateTime::fromInputs($this->date, $this->time),
            'notes' => $this->notes !== '' ? $this->notes : null,
        ]);

        session()->flash('m-toast', 'Approvisionnement de '.Fcfa::format($appro->amount).' enregistré.');

        return $this->redirectRoute('m.approvisionnements', navigate: true);
    }

    /** Retour d'UV d'un agent : approvisionnement à crédit sur le compte fournisseur de l'agent (RetourAgentService). */
    private function saveRetour()
    {
        if (! $this->agentId) {
            throw ValidationException::withMessages(['agentId' => 'Sélectionnez l\'agent qui rend ses UV.']);
        }

        try {
            $retour = app(RetourAgentService::class)->enregistrer(
                $this->agentId,
                $this->numericAmount,
                OperationDateTime::fromInputs($this->date, $this->time),
                $this->notes !== '' ? $this->notes : null,
            );
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['agentId' => collect($e->errors())->flatten()->implode(' ')]);
        }

        session()->flash('m-toast', 'Retour de '.Fcfa::format($retour->amount).' enregistré : à payer à l\'agent dans « Dettes fournisseurs ».');

        return $this->redirectRoute('m.approvisionnements', navigate: true);
    }

    public function render()
    {
        return view('livewire.mobile.approvisionnements.create');
    }
}
