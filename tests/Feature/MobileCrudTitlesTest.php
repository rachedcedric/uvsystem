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
