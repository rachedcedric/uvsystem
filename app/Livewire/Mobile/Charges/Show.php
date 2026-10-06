<?php

namespace App\Livewire\Mobile\Charges;

use App\Models\Charge;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Charge')]
class Show extends Component
{
    public Charge $charge;

    public function mount(Charge $charge): void
    {
        Gate::authorize('view', $charge);

        $this->charge = $charge->load(['category', 'creator']);
    }

    public function delete()
    {
        Gate::authorize('delete', $this->charge);

        $this->charge->delete();
        session()->flash('m-toast', 'Charge supprimée.');

        return $this->redirectRoute('m.charges', navigate: true);
    }

    public function render()
    {
        return view('livewire.mobile.charges.show', [
            'canEdit' => auth()->user()->can('update', $this->charge),
            'canDelete' => auth()->user()->can('delete', $this->charge),
        ]);
    }
}
