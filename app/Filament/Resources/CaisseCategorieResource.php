<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CaisseCategorieResource\Pages;
use App\Models\CaisseCategorie;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class CaisseCategorieResource extends Resource
{
    protected static ?string $model = CaisseCategorie::class;

    protected static ?string $slug = 'caisse-categories';

    protected static ?string $modelLabel = 'catégorie de caisse';

    protected static ?string $pluralModelLabel = 'catégories de caisse';

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Catégories de caisse';

    protected static ?string $navigationGroup = 'Paramètres du tenant';

    protected static ?int $navigationSort = 102;

    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->can('view_caisse');
    }

    public static function canCreate(): bool
    {
        return (bool) Auth::user()?->can('create_caisse');
    }

    public static function canEdit($record): bool
    {
        return (bool) Auth::user()?->can('edit_caisse');
    }

    public static function canDelete($record): bool
    {
        return (bool) Auth::user()?->can('delete_caisse') && ! $record->is_system && ! $record->isUsed();
    }

    public static function formSchema(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->label('Nom de la catégorie')
                ->required()
                ->maxLength(100)
                ->rules([
                    fn (?CaisseCategorie $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                        if (CaisseCategorie::nameIsTaken((string) $value, $record?->id)) {
                            $fail('Une catégorie portant ce nom existe déjà.');
                        }
                    },
                ]),
            Forms\Components\ColorPicker::make('color')->label('Couleur (optionnel)')->hex(),
            Forms\Components\TextInput::make('sort_order')
                ->label("Ordre d'affichage")
                ->numeric()->minValue(0)->default(0)->required(),
            Forms\Components\Toggle::make('is_active')->label('Active')->default(true),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ColorColumn::make('color')->label('Couleur'),
                Tables\Columns\TextColumn::make('name')->label('Catégorie')->weight('bold')->searchable()
                    ->description(fn (CaisseCategorie $r) => $r->is_system ? 'Catégorie système' : null),
                Tables\Columns\TextColumn::make('sort_order')->label('Ordre')->sortable(),
                Tables\Columns\TextColumn::make('mouvements_count')->counts('mouvements')->label('Mouvements'),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn () => static::canEdit(null)),
                Tables\Actions\Action::make('deactivate')
                    ->label('Désactiver')->icon('heroicon-o-eye-slash')->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Désactiver la catégorie ?')
                    ->modalDescription('Elle n\'apparaîtra plus dans les nouveaux formulaires, mais restera visible dans l\'historique et les exports.')
                    ->visible(fn (CaisseCategorie $r) => $r->is_active && ! $r->is_system && static::canEdit($r))
                    ->action(fn (CaisseCategorie $r) => $r->update(['is_active' => false])),
                Tables\Actions\Action::make('activate')
                    ->label('Réactiver')->icon('heroicon-o-eye')->color('success')
                    ->visible(fn (CaisseCategorie $r) => ! $r->is_active && static::canEdit($r))
                    ->action(fn (CaisseCategorie $r) => $r->update(['is_active' => true])),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (CaisseCategorie $r) => static::canDelete($r)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageCaisseCategories::route('/')];
    }
}
