<?php

namespace App\Livewire\Mobile\Crud;

use App\Exceptions\SubscriptionException;
use App\Support\Mobile\Crud\Field;
use App\Support\Mobile\Crud\MobileResource;
use App\Support\Mobile\Crud\Registry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
class Form extends Component
{
    public string $resource;

    public ?string $recordId = null;

    /** @var array<string,mixed> */
    public array $data = [];

    /** @return class-string<MobileResource> */
    private function res(): string
    {
        return Registry::find($this->resource) ?? abort(404);
    }

    private function record(): ?Model
    {
        return $this->recordId ? $this->res()::model()::findOrFail($this->recordId) : null;
    }

    /** @return list<Field> */
    private function fields(): array
    {
        $isEdit = $this->recordId !== null;

        return array_values(array_filter($this->res()::fields(), fn (Field $f) => $f->visibleOn($isEdit)));
    }

    public function mount(string $resource, ?string $id = null): void
    {
        $this->resource = $resource;
        $this->recordId = $id;
        $r = $this->res();
        $user = auth()->user();

        if ($id !== null) {
            $record = $this->record();
            abort_unless($r::can($user, 'update', $record), 403);
            $this->data = $r::toForm($record);

            return;
        }

        abort_unless($r::can($user, 'create'), 403);
        foreach ($this->fields() as $field) {
            $this->data[$field->name] = $field->initial();
        }
    }

    public function toggleChoice(string $name, string $value): void
    {
        $current = (array) ($this->data[$name] ?? []);
        $this->data[$name] = in_array($value, $current, true)
            ? array_values(array_diff($current, [$value]))
            : [...$current, $value];
    }

    public function save()
    {
        $r = $this->res();
        $record = $this->record();
        $isEdit = $record !== null;

        abort_unless($isEdit ? $r::can(auth()->user(), 'update', $record) : $r::can(auth()->user(), 'create'), 403);

        $rules = [];
        $attributes = [];
        foreach ($this->fields() as $field) {
            $rules['data.'.$field->name] = $field->validationRules($record, $isEdit);
            $attributes['data.'.$field->name] = mb_strtolower($field->label);
        }

        $this->validate($rules, self::messages(), $attributes);

        $clean = [];
        foreach ($this->fields() as $field) {
            $value = $this->data[$field->name] ?? null;
            if ($field->virtual || ($field->type === 'password' && ($value === null || $value === ''))) {
                continue;
            }
            $clean[$field->name] = $field->normalize($value);
        }

        try {
            $saved = $r::save($clean, $record);
        } catch (SubscriptionException $e) {
            $this->addError('form', collect($e->errors())->flatten()->implode(' '));

            return null;
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $key => $messages) {
                $errors[str_starts_with($key, 'data.') ? $key : 'data.'.$key] = implode(' ', $messages);
            }
            throw ValidationException::withMessages($errors);
        } catch (\Throwable $e) {
            report($e);
            $this->addError('form', 'Enregistrement impossible : '.$e->getMessage());

            return null;
        }

        session()->flash('m-toast', $r::label().($isEdit ? ' modifié.' : ' enregistré.'));

        return $this->redirect($r::showUrl($saved), navigate: true);
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return [
            'data.*.required' => 'Ce champ est obligatoire.',
            'data.*.email' => 'Adresse e-mail invalide.',
            'data.*.max' => 'Trop long (maximum :max).',
            'data.*.min' => 'Valeur trop petite (minimum :min).',
            'data.*.numeric' => 'Saisissez un nombre.',
            'data.*.regex' => 'Format invalide.',
            'data.*.unique' => 'Cette valeur est déjà utilisée.',
            'data.*.in' => 'Choix invalide.',
            'data.*.confirmed' => 'La confirmation ne correspond pas.',
            'data.*.date' => 'Date invalide.',
            'data.*.date_format' => 'Format d’heure invalide.',
        ];
    }

    public function render()
    {
        $r = $this->res();

        $record = $this->record();

        return view('livewire.mobile.crud.form', [
            'r' => $r,
            'backUrl' => $record ? $r::showUrl($record) : $r::indexUrl(),
            'fields' => $this->fields(),
            'isEdit' => $this->recordId !== null,
        ])->title($this->recordId ? 'Modifier — '.$r::label() : $r::newTitle());
    }
}
