<?php

namespace App\Livewire\Mobile\Ventes;

use App\Models\UvTransaction;
use App\Services\UvDistributionService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Vente')]
class Show extends Component
{
    public UvTransaction $transaction;

    public function mount(UvTransaction $transaction): void
    {
        Gate::authorize('view', $transaction);

        $this->transaction = $transaction->load(['agent', 'fournisseur']);
    }

    public function cancel(): void
    {
        Gate::authorize('update', $this->transaction);

        try {
            (new UvDistributionService)->cancel($this->transaction);
        } catch (\Throwable $e) {
            $this->dispatch('m-toast', message: 'Annulation impossible : '.$e->getMessage(), type: 'error');

            return;
        }

        $this->transaction = $this->transaction->fresh(['agent', 'fournisseur']);
        $this->dispatch('m-toast', message: 'Vente annulée. Le montant est recrédité au solde UV.');
    }

    public function duplicate()
    {
        Gate::authorize('create', UvTransaction::class);

        $t = $this->transaction;
        $service = new UvDistributionService;

        try {
            $new = $t->transaction_type === 'retour'
                ? $service->processReturn($t->agent, (float) $t->amount, now(), $t->notes)
                : $service->distribute($t->agent, (float) $t->amount, $t->fournisseur, now(), $t->notes);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->dispatch('m-toast', message: 'Duplication impossible : '.collect($e->errors())->flatten()->implode(' '), type: 'error');

            return null;
        }

        session()->flash('m-toast', 'Vente dupliquée : '.$new->reference.'.');

        return $this->redirectRoute('m.ventes.show', $new, navigate: true);
    }

    public function delete()
    {
        Gate::authorize('delete', $this->transaction);

        try {
            $this->transaction->delete();
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('m-toast', message: 'Suppression impossible : '.$e->getMessage(), type: 'error');

            return null;
        }

        session()->flash('m-toast', 'Vente supprimée.');

        return $this->redirectRoute('m.ventes', navigate: true);
    }

    public function render()
    {
        $t = $this->transaction;

        return view('livewire.mobile.ventes.show', [
            'canDuplicate' => $t->status !== 'cancelled' && $t->agent && auth()->user()->can('create', UvTransaction::class),
            'canEdit' => $t->status !== 'cancelled' && auth()->user()->can('update', $t),
            'canDelete' => auth()->user()->can('delete', $t),
            'canCancel' => $t->transaction_type === 'distribution'
                && $t->status !== 'cancelled'
                && auth()->user()->can('update', $t),
        ]);
    }
}
