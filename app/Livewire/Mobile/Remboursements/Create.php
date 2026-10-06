<?php

namespace App\Livewire\Mobile\Remboursements;

use App\Models\Agent;
use App\Models\PaymentMethod;
use App\Models\Remboursement;
use App\Services\RemboursementService;
use App\Services\ShortReferenceGenerator;
use App\Support\Mobile\Fcfa;
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
#[Title('Encaisser un remboursement')]
class Create extends Component
{
    #[Url(as: 'agent')]
    public ?int $agentId = null;

    public string $amount = '';

    public string $method = 'cash';

    public string $date = '';

    public string $time = '';

    public string $notes = '';

    public function mount(): void
    {
        Gate::authorize('create', Remboursement::class);

        if ($this->agentId && ! Agent::where('id', $this->agentId)->where('status', 'active')->exists()) {
            $this->agentId = null;
        }

        $options = $this->methods;
        if (! isset($options[$this->method])) {
            $this->method = (string) (array_key_first($options) ?? 'cash');
        }

        ['date' => $this->date, 'time' => $this->time] = OperationDateTime::defaults();
    }

    /** Agents actifs qui ont encore une dette à rembourser. */
    #[Computed]
    public function agents()
    {
        return Agent::active()->withDebt()->orderBy('name')->get(['id', 'name', 'phone', 'current_debt', 'debt_limit']);
    }

    #[Computed]
    public function agent(): ?Agent
    {
        return $this->agentId ? Agent::active()->find($this->agentId) : null;
    }

    /** Reste à payer recalculé en direct depuis les mouvements (même source que Filament). */
    #[Computed]
    public function debt(): float
    {
        return $this->agent?->debtAsOf() ?? 0.0;
    }

    #[Computed]
    public function methods(): array
    {
        return PaymentMethod::getActiveOptions();
    }

    #[Computed]
    public function numericAmount(): float
    {
        return Money::toFloat($this->amount);
    }

    #[Computed]
    public function remaining(): float
    {
        return max(0, $this->debt - $this->numericAmount);
    }

    public function payAll(): void
    {
        $this->amount = (string) (int) round($this->debt);
    }

    public function save()
    {
        Gate::authorize('create', Remboursement::class);

        $this->validate([
            'agentId' => ['required', 'integer'],
            'amount' => ['required'],
            'method' => ['required', 'string'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'agentId.required' => 'Choisissez un agent.',
            'amount.required' => 'Saisissez un montant.',
            'date.before_or_equal' => 'La date ne peut pas être dans le futur.',
        ]);

        $agent = $this->agent;

        if (! $agent) {
            throw ValidationException::withMessages(['agentId' => 'Cet agent est introuvable ou inactif.']);
        }

        if ($this->numericAmount < 1) {
            throw ValidationException::withMessages(['amount' => 'Le montant doit être supérieur à 0.']);
        }

        if ($this->numericAmount > $this->debt) {
            throw ValidationException::withMessages([
                'amount' => 'Le montant ne peut pas dépasser le reste à payer ('.Fcfa::format($this->debt).').',
            ]);
        }

        $operationAt = OperationDateTime::fromInputs($this->date, $this->time);

        try {
            $remboursement = app(RemboursementService::class)->process(
                $agent,
                $this->numericAmount,
                $this->method,
                $operationAt,
                $this->notes !== '' ? $this->notes : null,
                app(ShortReferenceGenerator::class)->generate('R', 'remboursements'),
            );
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                'amount' => collect($e->errors())->flatten()->implode(' '),
            ]);
        }

        session()->flash('m-toast', 'Remboursement '.$remboursement->reference.' enregistré.');

        return $this->redirectRoute('m.remboursements.show', $remboursement, navigate: true);
    }

    public function render()
    {
        return view('livewire.mobile.remboursements.create');
    }
}
