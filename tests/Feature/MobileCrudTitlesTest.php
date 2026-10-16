<?php

use App\Support\Mobile\Crud\Registry;

it('accorde le titre de création en genre et en élision', function () {
    $titles = collect(Registry::all())
        ->mapWithKeys(fn (string $resource) => [$resource::slug() => $resource::newTitle()]);

    expect($titles['agents'])->toBe('Nouvel agent')
        ->and($titles['agents-partenaires'])->toBe('Nouvel agent partenaire')
        ->and($titles['utilisateurs'])->toBe('Nouvel utilisateur')
        ->and($titles['approvisionnements'])->toBe('Nouvel approvisionnement')
        ->and($titles['ventes'])->toBe('Nouvelle vente')
        ->and($titles['charges'])->toBe('Nouvelle charge')
        ->and($titles['categories-charges'])->toBe('Nouvelle catégorie de charge')
        ->and($titles['categories-caisse'])->toBe('Nouvelle catégorie de caisse')
        ->and($titles['fournisseurs'])->toBe('Nouveau fournisseur')
        ->and($titles['remboursements'])->toBe('Nouveau remboursement');
});

it('annonce la vraie longueur minimale du mot de passe et écrit les rôles lisiblement', function () {
    $source = (string) file_get_contents(base_path('app/Support/Mobile/Crud/Resources/Utilisateurs.php'));
    $rule = (string) file_get_contents(base_path('app/Support/Mobile/Crud/Field.php'));

    // L'indication affichée doit correspondre à la règle réellement appliquée (min:12).
    expect($rule)->toContain("'password' => ['string', 'min:12']")
        ->and($source)->toContain("hint('12 caractères minimum')")
        ->and($source)->not->toContain('8 caractères')
        ->and($source)->toContain("str_replace('_', ' ', \$n)");
});
