<?php

namespace App\Livewire\Mobile\Caisse;

use App\Models\CaisseCategorie;
use App\Models\CaisseMouvement;
use App\Services\CaissePeriodResolver;
use App\Services\CaisseService;
use App\Support\Mobile\Fcfa;
use App\Support\Mobile\OperationDateTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Mouvements de caisse')]
class Create extends Component
{
    /** @var array<int, array{type: string, amount: string, libelle: string, categorieId: ?int, notes: string}> */
    public array $lines = [];

    public string $date = '';

    public string $time = '';

    public function mount(): void
    {
        Gate::authorize('create', CaisseMouvement::class);

        ['date' => $this->date, 'time' => $this->time] = OperationDateTime::defaults();

        // ?date=AAAA-MM-JJ (depuis une journée de caisse) : on ajoute des lignes à cette date.
        $requested = request()->query('date');

        if (is_string($requested) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested)) {
            try {
                $day = CarbonImmutable::createFromFormat('!Y-m-d', $requested, CaissePeriodResolver::TIMEZONE);

                if ($day->lessThanOrEqualTo(CarbonImmutable::now(CaissePeriodResolver::TIMEZONE)->endOfDay()) && $day->toDateString() !== $this->date) {
                    $this->date = $day->toDateString();
                    $this->time = '12:00';
                }
            } catch (\Throwable) {
                // Date illisible : on garde aujourd'hui.
            }
        }

        $this->lines = [$this->blankLine()];
    }

    /** @return array{type: string, amount: string, libelle: string, categorieId: ?int, notes: string} */
    private function blankLine(string $type = 'entree'): array
    {
        return ['type' => $type, 'amount' => '', 'libelle' => '', 'categorieId' => null, 'notes' => ''];
    }

    public function addLine(): void
    {
        // La nouvelle ligne reprend le type de la précédente : on saisit souvent plusieurs sorties à la suite.
        $this->lines[] = $this->blankLine(end($this->lines)['type'] ?? 'entree');
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) <= 1) {
            return;
        }

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        $this->resetErrorBag();
    }

    #[Computed]
    public function categories()
    {
        return CaisseCategorie::active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function solde(): float
    {
        return app(CaisseService::class)->soldeActuel();
    }

    #[Computed]
    public function totalEntrees(): float
    {
        return $this->sum('entree');
    }

    #[Computed]
    public function totalSorties(): float
    {
        return $this->sum('sortie');
    }

    private function sum(string $type): float
    {
        return collect($this->lines)->where('type', $type)->sum(fn ($line) => Money::toFloat($line['amount'] ?? 0));
    }

    public function save()
    {
        Gate::authorize('create', CaisseMouvement::class);

        $this->validate([
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.type' => ['required', 'in:entree,sortie'],
            'lines.*.amount' => ['required'],
            'lines.*.libelle' => ['required', 'string', 'max:150'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
        ], [
            'lines.*.amount.required' => 'Saisissez un montant.',
            'lines.*.libelle.required' => 'Indiquez un libellé.',
            'date.before_or_equal' => 'La date ne peut pas être dans le futur.',
        ]);

        $systemCategoryId = (int) CaisseCategorie::where('is_system', true)->value('id');

        $payload = collect($this->lines)->map(fn (array $line) => [
            'type' => $line['type'],
            'amount' => Money::toFloat($line['amount']),
            'libelle' => trim($line['libelle']),
            'caisse_categorie_id' => $line['categorieId'] ?: $systemCategoryId,
            'notes' => $line['notes'] !== '' ? $line['notes'] : null,
        ])->all();

        try {
            $created = app(CaisseService::class)->enregistrerLot(
                $payload,
                OperationDateTime::fromInputs($this->date, $this->time),
            );
        } catch (ValidationException $e) {
            // Les erreurs métier sont rattachées à la bonne ligne du formulaire.
            $errors = [];
            foreach ($e->errors() as $key => $messages) {
                $key = match (true) {
                    (bool) preg_match('/^mouvements\.(\d+)\.caisse_categorie_id$/', $key, $m) => "lines.{$m[1]}.categorieId",
                    (bool) preg_match('/^mouvements\.(\d+)\.(.+)$/', $key, $m) => "lines.{$m[1]}.{$m[2]}",
                    $key === 'mouvements' => 'lines',
                    $key === 'caisse_date' => 'time',
                    default => $key,
                };
                $errors[$key] = implode(' ', $messages);
            }

            throw ValidationException::withMessages($errors);
        }

        $entrees = $created->where('type', 'entree')->sum('amount');
        $sorties = $created->where('type', 'sortie')->sum('amount');

        session()->flash('m-toast', $created->count() === 1
            ? ($created->first()->type === 'entree' ? 'Entrée' : 'Sortie').' de '.Fcfa::format($created->first()->amount).' enregistrée.'
            : $created->count().' mouvements enregistrés (+'.Fcfa::format($entrees).' / −'.Fcfa::format($sorties).').');

        // On revient sur la journée concernée : on y voit le résultat et on peut y ajouter d'autres lignes.
        return $this->redirectRoute('m.caisse.jour', ['date' => $this->date], navigate: true);
    }

    public function render()
    {
        return view('livewire.mobile.caisse.create');
    }
}
