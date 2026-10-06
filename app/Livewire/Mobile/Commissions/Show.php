<?php

namespace App\Livewire\Mobile\Commissions;

use App\Models\Commission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Commission')]
class Show extends Component
{
    public Commission $commission;

    public function mount(Commission $commission): void
    {
        abort_unless($commission->is_global, 404);
        Gate::authorize('view', $commission);

        $this->commission = $commission;
    }

    public function render()
    {
        return view('livewire.mobile.commissions.show');
    }
}
