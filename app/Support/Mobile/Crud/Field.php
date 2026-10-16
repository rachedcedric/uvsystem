<?php

namespace App\Support\Mobile\Crud;

use App\Support\Money;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Champ de formulaire mobile (équivalent léger d'un composant de formulaire
 * Filament). Décrit le type, la validation et la normalisation de la valeur.
 */
final class Field
{
    public bool $required = false;

    public mixed $default = null;

    public ?string $hint = null;

    public ?string $placeholder = null;

    public ?string $suffix = null;

    /** @var array<string,string>|Closure */
    public array|Closure $options = [];

    /** @var list<mixed> */
    public array $rules = [];

    public ?string $uniqueTable = null;

    public ?string $uniqueColumn = null;

    /** 'both' | 'create' | 'edit' */
    public string $on = 'both';

    public ?float $min = null;

    public ?float $max = null;

    public ?int $maxLength = null;

    /** Champ de saisie seulement (non enregistré tel quel, ex. confirmation de mot de passe). */
    public bool $virtual = false;

    private function __construct(public string $type, public string $name, public string $label) {}

    public static function text(string $name, string $label): self
    {
        return new self('text', $name, $label);
    }

    public static function textarea(string $name, string $label): self
    {
        return new self('textarea', $name, $label);
    }

    public static function email(string $name, string $label): self
    {
        return new self('email', $name, $label);
    }

    public static function tel(string $name, string $label): self
    {
        return new self('tel', $name, $label);
    }

    public static function number(string $name, string $label): self
    {
        return new self('number', $name, $label);
    }

    public static function money(string $name, string $label): self
    {
        return new self('money', $name, $label);
    }

    /** @param array<string,string>|Closure $options */
    public static function select(string $name, string $label, array|Closure $options): self
    {
        $field = new self('select', $name, $label);
        $field->options = $options;

        return $field;
    }

    /** Sélection multiple (ex. rôles). @param array<string,string>|Closure $options */
    public static function multi(string $name, string $label, array|Closure $options): self
    {
        $field = new self('multi', $name, $label);
        $field->options = $options;

        return $field;
    }

    public static function toggle(string $name, string $label): self
    {
        return new self('toggle', $name, $label);
    }

    public static function date(string $name, string $label): self
    {
        return new self('date', $name, $label);
    }

    public static function datetime(string $name, string $label): self
    {
        return new self('datetime', $name, $label);
    }

    public static function time(string $name, string $label): self
    {
        return new self('time', $name, $label);
    }

    public static function password(string $name, string $label): self
    {
        return new self('password', $name, $label);
    }

    public static function color(string $name, string $label): self
    {
        return new self('color', $name, $label);
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;

        return $this;
    }

    public function hint(string $hint): self
    {
        $this->hint = $hint;

        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function suffix(string $suffix): self
    {
        $this->suffix = $suffix;

        return $this;
    }

    public function rules(mixed ...$rules): self
    {
        $this->rules = [...$this->rules, ...$rules];

        return $this;
    }

    public function unique(string $table, ?string $column = null): self
    {
        $this->uniqueTable = $table;
        $this->uniqueColumn = $column ?? $this->name;

        return $this;
    }

    public function min(float $min): self
    {
        $this->min = $min;

        return $this;
    }

    public function max(float $max): self
    {
        $this->max = $max;

        return $this;
    }

    public function maxLength(int $length): self
    {
        $this->maxLength = $length;

        return $this;
    }

    public function virtual(): self
    {
        $this->virtual = true;

        return $this;
    }

    public function onlyOnCreate(): self
    {
        $this->on = 'create';

        return $this;
    }

    public function onlyOnEdit(): self
    {
        $this->on = 'edit';

        return $this;
    }

    public function visibleOn(bool $isEdit): bool
    {
        return $this->on === 'both' || ($this->on === 'edit') === $isEdit;
    }

    /** @return array<string,string> */
    public function resolveOptions(): array
    {
        return $this->options instanceof Closure ? ($this->options)() : $this->options;
    }

    /**
     * Règles de validation Laravel du champ.
     *
     * @return list<mixed>
     */
    public function validationRules(?Model $record, bool $isEdit): array
    {
        $rules = [$this->required && ! ($this->type === 'password' && $isEdit) ? 'required' : 'nullable'];

        $rules = array_merge($rules, match ($this->type) {
            'email' => ['email'],
            'number' => ['numeric'],
            'money' => ['regex:/^\d{1,12}$/'],
            'date' => ['date'],
            'datetime' => ['date'],
            'time' => ['date_format:H:i'],
            'toggle' => ['boolean'],
            'multi' => ['array'],
            'color' => ['regex:/^#[0-9a-fA-F]{6}$/'],
            'password' => ['string', 'min:12'],
            'select' => [Rule::in(array_map('strval', array_keys($this->resolveOptions())))],
            default => ['string'],
        });

        if ($this->maxLength) {
            $rules[] = 'max:'.$this->maxLength;
        }
        if ($this->min !== null && in_array($this->type, ['number', 'money'], true)) {
            $rules[] = 'min:'.$this->min;
        }
        if ($this->max !== null && in_array($this->type, ['number', 'money'], true)) {
            $rules[] = 'max:'.$this->max;
        }
        if ($this->uniqueTable) {
            $unique = Rule::unique($this->uniqueTable, $this->uniqueColumn);
            // L'unicité est PAR tenant (sauf users : l'e-mail sert d'identifiant de connexion global).
            if ($this->uniqueTable !== 'users') {
                $unique->where('tenant_id', TenantContext::id());
            }
            $rules[] = $record ? $unique->ignore($record->getKey()) : $unique;
        }

        return array_merge($rules, $this->rules);
    }

    /** Valeur par défaut affichée dans le formulaire de création. */
    public function initial(): mixed
    {
        return match ($this->type) {
            'toggle' => (bool) ($this->default ?? false),
            'multi' => (array) ($this->default ?? []),
            'money' => $this->default !== null ? (string) (int) $this->default : '',
            default => $this->default !== null ? (string) $this->default : '',
        };
    }

    /** Valeur issue d'un enregistrement existant, prête pour le formulaire. */
    public function fromRecord(mixed $value): mixed
    {
        return match ($this->type) {
            'toggle' => (bool) $value,
            'multi' => (array) $value,
            'money' => $value === null ? '' : (string) (int) round((float) $value),
            'date' => $value ? Carbon::parse($value)->toDateString() : '',
            'datetime' => $value ? Carbon::parse($value)->format('Y-m-d\TH:i') : '',
            'time' => $value ? substr((string) $value, 0, 5) : '',
            'password' => '',
            default => $value === null ? '' : (string) $value,
        };
    }

    /** Valeur validée -> valeur à enregistrer. */
    public function normalize(mixed $value): mixed
    {
        return match ($this->type) {
            'toggle' => (bool) $value,
            'multi' => array_values((array) $value),
            'money' => ($value === null || $value === '') ? null : Money::toFloat($value),
            'datetime' => ($value === null || $value === '') ? null : Carbon::parse($value)->toDateTimeString(),
            'time' => ($value === null || $value === '') ? null : substr((string) $value, 0, 5),
            'number' => ($value === null || $value === '') ? null : $value + 0,
            default => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value),
        };
    }
}
