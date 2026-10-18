<?php

use App\Support\Mobile\Crud\Registry;
use App\Support\PasswordPolicy;

it('accorde le titre de création en genre et en élision', function () {
    $titles = collect(Registry::all())
        ->mapWithKeys(fn (string $resource) => [$resource::slug() => $resource::newTitle()]);

    expect($titles['agents'])->toBe('Nouvel agent')
        ->and($titles)->not->toHaveKeys(['agents-partenaires', 'sous-distributeurs'])
        ->and($titles['utilisateurs'])->toBe('Nouvel utilisateur')
        ->and($titles['approvisionnements'])->toBe('Nouvel approvisionnement')
        ->and($titles['ventes'])->toBe('Nouvelle vente')
        ->and($titles['charges'])->toBe('Nouvelle charge')
        ->and($titles['categories-charges'])->toBe('Nouvelle catégorie de charge')
        ->and($titles['categories-caisse'])->toBe('Nouvelle catégorie de caisse')
        ->and($titles['fournisseurs'])->toBe('Nouveau fournisseur')
        ->and($titles['remboursements'])->toBe('Nouveau remboursement');
});

it('applique partout la même règle de mot de passe et écrit les rôles lisiblement', function () {
    $field = (string) file_get_contents(base_path('app/Support/Mobile/Crud/Field.php'));
    $users = (string) file_get_contents(base_path('app/Support/Mobile/Crud/Resources/Utilisateurs.php'));

    expect($field)->toContain('PasswordPolicy::rule()')
        ->and($users)->toContain('PasswordPolicy::HINT')
        ->and($users)->not->toContain('8 caractères')
        ->and($users)->toContain("str_replace('_', ' ', \$n)");
});

it('refuse un mot de passe sans majuscule, chiffre ou symbole et accepte un mot de passe conforme', function (string $password, bool $valid) {
    $passes = validator(['password' => $password], ['password' => PasswordPolicy::rule()])->passes();

    expect($passes)->toBe($valid);
})->with([
    'trop court' => ['Court#1a', false],
    'sans majuscule' => ['motdepasse#1234', false],
    'sans chiffre' => ['Motdepasse#abcd', false],
    'sans symbole' => ['Motdepasse12345', false],
    'conforme' => ['Motdepasse#1234', true],
]);

it('affiche la même règle de mot de passe sur les pages publiques et de sécurité', function () {
    foreach (['auth/account-security', 'auth/reset-password', 'public/inscription'] as $view) {
        $source = (string) file_get_contents(resource_path("views/{$view}.blade.php"));

        expect($source)->toContain('PasswordPolicy::HINT');
    }
});
