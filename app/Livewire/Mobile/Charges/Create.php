<?php

namespace App\Livewire\Mobile\Charges;

use App\Models\Charge;
use App\Models\ChargeCategorie;
use App\Support\Mobile\Fcfa;
use App\Support\Mobile\OperationDateTime;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.mobile')]
#[Title('Nouvelle charge')]
class Create extends Component
{
    use WithFileUploads;

    public string $libelle = '';

    public ?int $categoryId = null;

    public string $amount = '';

    public string $date = '';

    public string $time = '';

    public string $description = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $justificatif = null;

    public function mount(): void
    {
        Gate::authorize('create', Charge::class);

        ['date' => $this->date, 'time' => $this->time] = OperationDateTime::defaults();
    }

    #[Computed]
    public function categories(): array
    {
        return ChargeCategorie::getActiveOptions();
    }

    #[Computed]
    public function numericAmount(): float
    {
        return Money::toFloat($this->amount);
    }

    public function removeJustificatif(): void
    {
        $this->justificatif = null;
    }

    public function save()
    {
        Gate::authorize('create', Charge::class);

        $this->validate([
            'libelle' => ['required', 'string', 'max:255'],
            'categoryId' => ['required', 'integer'],
            'amount' => ['required'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'description' => ['nullable', 'string', 'max:1000'],
            'justificatif' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], [
            'libelle.required' => 'Indiquez le libellé de la charge.',
            'categoryId.required' => 'Choisissez une catégorie.',
            'amount.required' => 'Saisissez un montant.',
            'date.before_or_equal' => 'La date ne peut pas être dans le futur.',
            'justificatif.mimes' => 'Le justificatif doit être un PDF ou une image (JPG, PNG).',
            'justificatif.max' => 'Le justificatif ne doit pas dépasser 5 Mo.',
        ]);

        if ($this->numericAmount < 1) {
            throw ValidationException::withMessages(['amount' => 'Le montant doit être supérieur à 0.']);
        }

        if (! array_key_exists($this->categoryId, $this->categories)) {
            throw ValidationException::withMessages(['categoryId' => 'Choisissez une catégorie active.']);
        }

        OperationDateTime::fromInputs($this->date, $this->time);

        $charge = Charge::create([
            'libelle' => trim($this->libelle),
            'category_id' => $this->categoryId,
            'montant' => $this->numericAmount,
            'date_charge' => $this->date,
            'heure_charge' => $this->time,
            'description' => $this->description !== '' ? $this->description : null,
            // Même disque privé que la version Filament (jamais exposé publiquement).
            'justificatif_path' => $this->justificatif?->store(TenantContext::storagePath('charges'), 'local'),
            'created_by' => auth()->id(),
        ]);

        session()->flash('m-toast', 'Charge de '.Fcfa::format($charge->montant).' enregistrée.');

        return $this->redirectRoute('m.charges', navigate: true);
    }

    public function render()
    {
        return view('livewire.mobile.charges.create');
    }
}
