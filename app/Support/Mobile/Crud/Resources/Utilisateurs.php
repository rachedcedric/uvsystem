<?php

namespace App\Support\Mobile\Crud\Resources;

use App\Models\User;
use App\Security\RoleAssignmentGuard;
use App\Support\Mobile\Crud\Field;
use App\Support\Mobile\Crud\MobileResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class Utilisateurs extends MobileResource
{
    public static function slug(): string
    {
        return 'utilisateurs';
    }

    public static function model(): string
    {
        return User::class;
    }

    public static function label(): string
    {
        return 'Utilisateur';
    }

    public static function plural(): string
    {
        return 'Utilisateurs';
    }

    public static function icon(): string
    {
        return 'user-circle';
    }

    public static function group(): string
    {
        return 'Administration';
    }

    public static function searchColumns(): array
    {
        return ['name', 'email'];
    }

    public static function searchPlaceholder(): string
    {
        return 'Nom ou e-mail';
    }

    public static function filters(): array
    {
        return ['all' => 'Tous'] + Role::whereIn('name', User::assignableRoleNames())->orderBy('name')->pluck('name', 'name')->map(fn ($n) => ucfirst($n))->all();
    }

    public static function applyFilter(Builder $query, string $filter): Builder
    {
        return $query->whereHas('roles', fn (Builder $r) => $r->where('name', $filter));
    }

    public static function order(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    public static function fields(): array
    {
        return [
            Field::text('name', 'Nom')->required()->maxLength(255),
            Field::email('email', 'E-mail')->required()->maxLength(255)->unique('users'),
            Field::password('password', 'Mot de passe')->required()->hint('8 caractères minimum'),
            Field::password('password_confirmation', 'Confirmer le mot de passe')->virtual()->rules('same:data.password', 'required_with:data.password'),
            // Champ présent seulement pour qui peut attribuer des rôles ; un admin ne se voit pas proposer « master ».
            ...(RoleAssignmentGuard::canAssignRoles(auth()->user()) ? [
                Field::multi('roles', 'Rôles', fn () => Role::whereIn('name', RoleAssignmentGuard::assignableFor(auth()->user()))->orderBy('name')->pluck('name', 'name')->map(fn ($n) => ucfirst($n))->all())
                    ->hint('Master et admin : administration complète de l\'entreprise (seul un master nomme un master) · Comptable : finances · Commercial : agents et transactions'),
            ] : []),
        ];
    }

    public static function toForm(Model $record): array
    {
        // array_merge (et non « + ») : le parent fournit déjà une clé « roles » (la relation) qu'il faut remplacer
        // par la liste des noms de rôles attendue par le champ à choix multiples.
        return array_merge(parent::toForm($record), ['roles' => $record->getRoleNames()->all()]);
    }

    public static function save(array $data, ?Model $record): Model
    {
        $rolesSubmitted = array_key_exists('roles', $data);
        $roles = (array) ($data['roles'] ?? []);
        unset($data['roles']);

        // Règle unique d'attribution des rôles (élévation de privilèges, comptes master, dernier master) : voir le garde.
        if ($rolesSubmitted) {
            RoleAssignmentGuard::assertCanAssign(auth()->user(), $record, $roles);
        }

        $user = $record ?? new User;
        $user->fill($data)->save();

        if ($rolesSubmitted) { // sans le champ, les rôles existants ne sont JAMAIS effacés
            $user->syncRoles($roles);
        }

        return $user;
    }

    public static function canDelete(User $user, Model $record): bool
    {
        return $user->getKey() !== $record->getKey() && static::can($user, 'delete', $record);
    }

    public static function row(Model $record): array
    {
        return [
            'title' => $record->name,
            'sub' => $record->email,
            'badge' => ucfirst((string) $record->getRoleNames()->first()),
            'badgeTone' => 'brand',
        ];
    }

    public static function details(Model $record): array
    {
        return [
            'E-mail' => (string) $record->email,
            'Rôles' => $record->getRoleNames()->map(fn ($r) => ucfirst($r))->implode(', '),
            'Double authentification' => $record->two_factor_enabled ? 'Activée' : 'Désactivée',
            'Créé le' => $record->created_at?->translatedFormat('j F Y') ?? '',
        ];
    }

    public static function deleteWarning(Model $record): string
    {
        return 'Le compte ne pourra plus se connecter.';
    }
}
