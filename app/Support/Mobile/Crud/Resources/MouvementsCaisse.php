<?php

namespace App\Support\Mobile\Crud\Resources;

use App\Models\CaisseCategorie;
use App\Models\CaisseMouvement;
use App\Models\User;
use App\Services\CaissePeriodResolver;
use App\Services\CaisseService;
use App\Support\Mobile\Crud\Field;
use App\Support\Mobile\Crud\MobileResource;
use App\Support\Mobile\Fcfa;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class MouvementsCaisse extends MobileResource
{
    public static function slug(): string
    {
        return 'mouvements-caisse';
    }

    public static function model(): string
    {
        return CaisseMouvement::class;
    }

    public static function label(): string
    {
        return 'Mouvement de caisse';
    }

    public static function plural(): string
    {
        return 'Mouvements de caisse';
    }

    public static function hidden(): bool
    {
        return true;
    }

    public static function can(User $user, string $ability, ?Model $record = null): bool
    {
        return match ($ability) {
            'viewAny', 'view' => $user->can('view_caisse'),
            'create' => $user->can('create_caisse'),
            'update' => $user->can('edit_caisse'),
            default => $user->can('delete_caisse'),
        };
    }

    public static function indexUrl(): string
    {
        return route('m.caisse');
    }

    public static function showUrl(Model $record): string
    {
        $date = app(CaissePeriodResolver::class)->businessDateFor($record->date_mouvement)->toDateString();

        return route('m.caisse.jour', $date);
    }

    public static function fields(): array
    {
        return [
            Field::select('type', 'Type', [CaisseMouvement::TYPE_ENTREE => 'Entrée', CaisseMouvement::TYPE_SORTIE => 'Sortie'])->required(),
            Field::select('caisse_categorie_id', 'Catégorie', fn () => CaisseCategorie::active()->orderBy('sort_order')->pluck('name', 'id')->all())->required(),
            Field::text('libelle', 'Libellé')->required()->maxLength(255),
            Field::money('amount', 'Montant (FCFA)')->required()->min(1),
            Field::datetime('date_mouvement', 'Date et heure')->required(),
        ];
    }

    public static function row(Model $record): array
    {
        return [
            'title' => $record->libelle,
            'sub' => $record->categorie?->name,
            'icon' => $record->type === 'entree' ? 'arrow-down-left' : 'arrow-up-right',
            'end' => ($record->type === 'entree' ? '+' : '−').Fcfa::number($record->amount),
        ];
    }

    public static function details(Model $record): array
    {
        return [
            'Type' => $record->type === 'entree' ? 'Entrée' : 'Sortie',
            'Catégorie' => (string) $record->categorie?->name,
            'Date' => $record->date_mouvement->translatedFormat('j F Y, H:i'),
            'Libellé' => (string) $record->libelle,
            'Montant' => Fcfa::format($record->amount),
            'Référence' => (string) $record->reference,
            'Enregistré par' => (string) $record->user?->name,
        ];
    }

    public static function save(array $data, ?Model $record): Model
    {
        if (Carbon::parse($data['date_mouvement'])->isFuture()) {
            throw ValidationException::withMessages(['date_mouvement' => 'La date et l’heure ne peuvent pas être dans le futur.']);
        }

        app(CaisseService::class)->validateMouvement($data['type'], (float) $data['amount']);

        return parent::save($data, $record);
    }

    public static function deleteWarning(Model $record): string
    {
        return 'Le mouvement est retiré de la caisse et le solde est recalculé.';
    }
}
