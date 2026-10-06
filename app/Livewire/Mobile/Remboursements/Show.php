<?php

namespace App\Livewire\Mobile\Remboursements;

use App\Models\PaymentMethod;
use App\Models\Remboursement;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Remboursement')]
class Show extends Component
{
    public Remboursement $remboursement;

    public function mount(Remboursement $remboursement): void
    {
        Gate::authorize('view', $remboursement);

        $this->remboursement = $remboursement->load('agent');
    }

    public function delete()
    {
        Gate::authorize('delete', $this->remboursement);

        try {
            $this->remboursement->delete();
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('m-toast', message: 'Suppression impossible : '.$e->getMessage(), type: 'error');

            return null;
        }

        session()->flash('m-toast', 'Remboursement supprimé.');

        return $this->redirectRoute('m.remboursements', navigate: true);
    }

    public function render()
    {
        return view('livewire.mobile.remboursements.show', [
            'method' => PaymentMethod::where('code', $this->remboursement->payment_method)->first(),
            'canEdit' => auth()->user()->can('update', $this->remboursement),
            'canDelete' => auth()->user()->can('delete', $this->remboursement),
        ]);
    }
}
