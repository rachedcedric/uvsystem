<?php

namespace App\Filament\Platform\Pages\Cms;

use App\Cms\Content;
use App\Cms\ContentRegistry;
use App\Models\CmsEntry;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Page d'édition d'une section du CMS. Le formulaire est GÉNÉRÉ depuis le registre : seules les clés qu'il liste
 * sont modifiables. Accès réservé au super admin de plateforme (User::isSuperAdmin()), revérifié à l'enregistrement.
 * Un champ vide ou identique à la valeur d'origine supprime la surcharge (retour au texte du code).
 */
abstract class CmsSectionPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'CMS';

    protected static string $view = 'filament.platform.cms-section';

    /** @var array<string, mixed> */
    public ?array $data = [];

    abstract protected static function section(): string;

    /** Page publique à prévisualiser (null = aucune). */
    protected static function previewUrl(): ?string
    {
        return null;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill($this->currentState());
    }

    public function getSectionDescription(): string
    {
        return ContentRegistry::sections()[static::section()]['description'];
    }

    public static function fieldName(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /** @return array<string, string> nom de champ => surcharge enregistrée (vide = valeur d'origine) */
    protected function currentState(): array
    {
        $state = [];
        foreach (ContentRegistry::entries(static::section()) as $key => $definition) {
            $state[self::fieldName($key)] = Content::override($key) ?? '';
        }

        return $state;
    }

    public function form(Form $form): Form
    {
        $sections = [];
        foreach (ContentRegistry::entries(static::section()) as $key => $definition) {
            $group = explode('.', $key)[1] ?? 'general';
            $sections[$group]['title'] ??= ContentRegistry::groupTitle(static::section(), $group, Str::contains($definition['label'], ' : ') ? Str::before($definition['label'], ' : ') : ucfirst(str_replace('_', ' ', $group)));
            $sections[$group]['fields'][] = $this->field($key, $definition);
        }

        $first = true;
        $schema = [];
        foreach ($sections as $section) {
            $schema[] = Section::make($section['title'])->schema($section['fields'])->columns(1)
                ->collapsible()->collapsed(! $first && count($sections) > 4);
            $first = false;
        }

        return $form->schema($schema)->statePath('data');
    }

    /** @param  array{label: string, default: string, type: string, max: int, help: ?string}  $definition */
    private function field(string $key, array $definition): Component
    {
        $name = self::fieldName($key);
        $label = Str::contains($definition['label'], ' : ') ? Str::after($definition['label'], ' : ') : $definition['label'];
        $default = $definition['default'];
        $help = trim(($definition['help'] ? $definition['help'].' ' : '').($default !== '' ? 'Valeur d\'origine : « '.Str::limit(str_replace("\n", ' / ', $default), 160).' »' : 'Vide par défaut.'));

        $field = $definition['type'] === 'multiline'
            ? Textarea::make($name)->rows(3)->maxLength($definition['max'])
            : TextInput::make($name)->maxLength($definition['max']);

        $field->label($label)->helperText($help)->placeholder(Str::limit(str_replace("\n", ' / ', $default), 80) ?: null);

        return match ($definition['type']) {
            'email' => $field->email(),
            'phone' => $field->tel()->regex('/^[0-9+ ().-]{6,30}$/'),
            'url' => $field->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                if (filled($value) && ! preg_match('#^(https://|http://|/[^/])#', (string) $value)) {
                    $fail('Saisissez une adresse commençant par https:// (ou un chemin du site comme /conditions).');
                }
            }),
            default => $field,
        };
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $changes = [];

        DB::transaction(function () use ($state, &$changes) {
            foreach (ContentRegistry::entries(static::section()) as $key => $definition) {
                $new = $this->normalize((string) ($state[self::fieldName($key)] ?? ''), $definition['type']);
                $new = ($new === '' || $new === $definition['default']) ? null : $new;
                $old = CmsEntry::query()->where('key', $key)->value('value');

                if ($new === $old) {
                    continue;
                }

                $new === null
                    ? CmsEntry::query()->where('key', $key)->delete()
                    : CmsEntry::query()->updateOrCreate(['key' => $key], ['value' => $new, 'updated_by' => auth()->id()]);

                $changes[$key] = ['de' => Str::limit((string) $old, 200), 'vers' => Str::limit((string) $new, 200)];
            }
        });

        Content::flush();

        if ($changes) {
            activity('cms')->causedBy(auth()->user())->withProperties(['section' => static::section(), 'modifications' => $changes])
                ->log('Contenu du CMS modifié : '.count($changes).' élément(s) ('.ContentRegistry::sections()[static::section()]['label'].')');
        }

        Notification::make()->title($changes ? count($changes).' élément(s) enregistré(s)' : 'Aucune modification')->success()->send();
        $this->form->fill($this->currentState());
    }

    /** Texte brut : aucune balise HTML conservée, fins de ligne unifiées. */
    private function normalize(string $value, string $type): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", strip_tags($value)));

        return $type === 'multiline' ? implode("\n", array_map('trim', explode("\n", $value))) : preg_replace('/\s+/', ' ', $value);
    }

    protected function getHeaderActions(): array
    {
        $actions = [];

        if ($url = static::previewUrl()) {
            $actions[] = Action::make('preview')->label('Voir la page')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')->url($url, shouldOpenInNewTab: true);
        }

        $actions[] = Action::make('reset')->label('Tout rétablir')->icon('heroicon-o-arrow-uturn-left')->color('danger')
            ->requiresConfirmation()->modalDescription('Tous les textes de cette section reviennent à leur valeur d\'origine.')
            ->action(function () {
                abort_unless(static::canAccess(), 403);

                $keys = array_keys(ContentRegistry::entries(static::section()));
                $count = CmsEntry::query()->whereIn('key', $keys)->delete();
                Content::flush();

                if ($count) {
                    activity('cms')->causedBy(auth()->user())->withProperties(['section' => static::section(), 'rétablis' => $count])->log('Contenu du CMS rétabli ('.ContentRegistry::sections()[static::section()]['label'].')');
                }

                $this->form->fill($this->currentState());
                Notification::make()->title('Valeurs d\'origine rétablies')->success()->send();
            });

        return $actions;
    }
}
