<?php

namespace App\Livewire\Mobile\Ventes;

use App\Models\Agent;
use App\Models\UvTransaction;
use App\Services\MasterSoldeService;
use App\Services\UvDistributionService;
use App\Support\Mobile\OperationDateTime;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Nouvelle vente')]
class Create extends Component
{
    #[Url(as: 'agent')]
    public ?int $agentId = null;

    /** Chiffres uniquement (le formatage "1 250 000" est purement visuel). */
    public string $amount = '';

    public string $date = '';

    public string $time = '';

    public string $notes = '';

    public function mount(): void
    {
        Gate::authorize('create', UvTransaction::class);

        if ($this->agentId && ! Agent::where('id', $this->agentId)->where('status', 'active')->exists()) {
            $this->agentId = null;
        }

        ['date' => $this->date, 'time' => $this->time] = OperationDateTime::defaults();
    }

    #[Computed]
    public function agents()
    {
        return Agent::active()->orderBy('name')->get(['id', 'name', 'phone', 'current_debt', 'debt_limit']);
    }

    #[Computed]
    public function agent(): ?Agent
    {
        return $this->agentId ? $this->agents->firstWhere('id', $this->agentId) : null;
    }

    #[Computed]
    public function credit(): ?float
    {
        if (! $this->agent) {
            return null;
        }

        $credit = (new UvDistributionService)->getAvailableCredit($this->agent);

        return $credit === PHP_FLOAT_MAX ? null : $credit;
    }

    #[Computed]
    public function unlimited(): bool
    {
        return $this->agent !== null && (new UvDistributionService)->getAvailableCredit($this->agent) === PHP_FLOAT_MAX;
    }

    #[Computed]
    public function masterBalance(): float
    {
        return app(MasterSoldeService::class)->getAvailableBalance();
    }

    #[Computed]
    public function numericAmount(): float
    {
        return Money::toFloat($this->amount);
    }

    #[Computed]
    public function warning(): ?string
    {
        $amount = $this->numericAmount;

        if ($amount <= 0) {
            return null;
        }

        if ($amount > $this->masterBalance) {
            return 'Solde UV insuffisant : '.\App\Support\Mobile\Fcfa::format($this->masterBalance).' disponibles.';
        }

        if ($this->agent && ! $this->unlimited && $this->credit !== null && $amount > $this->credit) {
            return 'Ce montant dépasse le crédit disponible de l\'agent ('.\App\Support\Mobile\Fcfa::format($this->credit).').';
        }

        return null;
    }

    public function save()
    {
        Gate::authorize('create', UvTransaction::class);

        $this->validate([
            'agentId' => ['required', 'integer'],
            'amount' => ['required'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'agentId.required' => 'Choisissez un agent.',
            'amount.required' => 'Saisissez un montant.',
            'date.before_or_equal' => 'La date ne peut pas être dans le futur.',
        ]);

        if ($this->numericAmount < 1) {
            throw ValidationException::withMessages(['amount' => 'Le montant doit être supérieur à 0.']);
        }

        $agent = Agent::where('id', $this->agentId)->where('status', 'active')->first();

        if (! $agent) {
            throw ValidationException::withMessages(['agentId' => 'Cet agent est introuvable ou inactif.']);
        }

        $operationAt = OperationDateTime::fromInputs($this->date, $this->time);

        try {
            $transaction = (new UvDistributionService)->distribute(
                $agent,
                $this->numericAmount,
                null,
                $operationAt,
                $this->notes !== '' ? $this->notes : null,
            );
        } catch (ValidationException $e) {
            // Les règles métier (solde, plafond de dette) remontent sous la clé "amount".
            throw ValidationException::withMessages([
                'amount' => collect($e->errors())->flatten()->implode(' '),
            ]);
        }

        session()->flash('m-toast', 'Vente '.$transaction->reference.' enregistrée.');

        return $this->redirectRoute('m.ventes.show', $transaction, navigate: true);
    }

    public function render()
    {
        return view('livewire.mobile.ventes.create');
    }
}
