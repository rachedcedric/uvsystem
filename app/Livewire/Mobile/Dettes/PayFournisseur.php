<?php

namespace App\Livewire\Mobile\Dettes;

use App\Models\Fournisseur;
use App\Models\PaymentMethod;
use App\Services\FournisseurDebtService;
use App\Support\Mobile\Fcfa;
use App\Support\Mobile\OperationDateTime;
use App\Support\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Payer un fournisseur')]
class PayFournisseur extends Component
{
    public Fournisseur $fournisseur;

    public string $amount = '';

    public string $method = 'cash';

    public string $date = '';

    public string $time = '';

    public string $notes = '';

    public function mount(Fournisseur $fournisseur): void
    {
        abort_unless(auth()->user()->can('create_remboursements_fournisseurs'), 403);
        abort_unless($fournisseur->is_active && $fournisseur->isCreditFournisseur(), 404);

        $this->fournisseur = $fournisseur;
        $methods = $this->methods;
        if (! isset($methods[$this->method])) {
            $this->method = (string) (array_key_first($methods) ?? 'cash');
        }

        ['date' => $this->date, 'time' => $this->time] = OperationDateTime::defaults();
    }

    #[Computed]
    public function totals(): array
    {
        return app(FournisseurDebtService::class)->soldeTotals($this->fournisseur);
    }

    /** Solde signé actuel (positif : dette ; négatif : découvert). */
    #[Computed]
    public function reste(): float
    {
        return app(FournisseurDebtService::class)->detteRestante($this->fournisseur);
    }

    /** Solde après ce paiement : un montant au-delà de la dette devient un découvert (avance). */
    #[Computed]
    public function apres(): float
    {
        return $this->reste - $this->numericAmount;
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

    public function payAll(): void
    {
        $this->amount = (string) (int) round(max(0, $this->reste));
    }

    public function save()
    {
        abort_unless(auth()->user()->can('create_remboursements_fournisseurs'), 403);

        $this->validate([
            'amount' => ['required'],
            'method' => ['required', 'string'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.required' => 'Saisissez un montant.',
            'date.before_or_equal' => 'La date ne peut pas être dans le futur.',
        ]);

        $operationAt = OperationDateTime::fromInputs($this->date, $this->time);

        try {
            $paiement = app(FournisseurDebtService::class)->process(
                $this->fournisseur,
                $this->numericAmount,
                $this->method,
                $operationAt,
                $this->notes !== '' ? $this->notes : null,
            );
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['amount' => collect($e->errors())->flatten()->implode(' ')]);
        }

        session()->flash('m-toast', 'Paiement de '.Fcfa::format($paiement->amount).' à '.$this->fournisseur->name.' enregistré.');

        return $this->redirectRoute('m.dettes.fournisseur.show', $this->fournisseur, navigate: true);
    }

    public function render()
    {
        return view('livewire.mobile.dettes.pay-fournisseur');
    }
}
