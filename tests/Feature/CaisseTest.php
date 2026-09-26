<?php

use App\Filament\Pages\MaCaisseJourPage;
use App\Filament\Pages\MaCaissePage;
use App\Filament\Resources\CaisseCategorieResource\Pages\ManageCaisseCategories;
use App\Filament\Widgets\CaisseStatsWidget;
use App\Models\CaisseCategorie;
use App\Models\CaisseMouvement;
use App\Models\User;
use App\Services\CaissePeriodResolver;
use App\Services\CaisseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const CAISSE_TZ = 'Africa/Abidjan';

function caisseAdmin(): User
{
    foreach (['view_caisse', 'create_caisse', 'edit_caisse', 'delete_caisse'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
    $role->givePermissionTo(['view_caisse', 'create_caisse', 'edit_caisse', 'delete_caisse']);

    $admin = User::factory()->create();
    $admin->assignRole('master');

    return $admin;
}

function caisseAt(string $when, string $type, int|string $amount, ?CaisseCategorie $cat = null, string $libelle = 'Test'): CaisseMouvement
{
    return CaisseMouvement::create([
        'type' => $type,
        'amount' => $amount,
        'libelle' => $libelle,
        'caisse_categorie_id' => ($cat ?? CaisseCategorie::where('is_system', true)->first())->id,
        'date_mouvement' => Carbon::parse($when, CAISSE_TZ),
    ]);
}

function cat(string $name): CaisseCategorie
{
    return CaisseCategorie::where('name', $name)->firstOrFail();
}

afterEach(fn () => Carbon::setTestNow());

describe('Journée de caisse 08h30 → 08h29:59', function () {
    it('bascule exactement à 08:30:00 (08:29:59 appartient à la veille)', function () {
        $r = app(CaissePeriodResolver::class);

        expect($r->businessDateFor(Carbon::parse('2026-09-26 08:29:59', CAISSE_TZ))->toDateString())->toBe('2026-09-25')
            ->and($r->businessDateFor(Carbon::parse('2026-09-26 08:30:00', CAISSE_TZ))->toDateString())->toBe('2026-09-26')
            ->and($r->businessDateFor(Carbon::parse('2026-09-25 08:30:00', CAISSE_TZ))->toDateString())->toBe('2026-09-25')
            ->and($r->businessDateFor(Carbon::parse('2026-09-25 00:10:00', CAISSE_TZ))->toDateString())->toBe('2026-09-24');
    });

    it('range les mouvements de part et d\'autre de la limite dans la bonne journée', function () {
        $admin = caisseAdmin();
        $this->actingAs($admin);

        caisseAt('2026-09-26 08:29:59', 'entree', 1000);
        caisseAt('2026-09-26 08:30:00', 'entree', 500);

        $service = app(CaisseService::class);
        $veille = Carbon::parse('2026-09-25', CAISSE_TZ);
        $jour = Carbon::parse('2026-09-26', CAISSE_TZ);

        expect($service->dayTotals($veille)['solde'])->toBe('1000.00')
            ->and($service->dayTotals($jour)['solde'])->toBe('500.00');
    });

    it('les widgets affichent 0 FCFA à 08:30 puis le solde de la veille dans "Solde hier"', function () {
        $admin = caisseAdmin();
        $this->actingAs($admin);
        caisseAt('2026-09-25 12:00:00', 'entree', 13545550);

        Carbon::setTestNow(Carbon::parse('2026-09-26 08:29:59', CAISSE_TZ));
        Livewire::test(CaisseStatsWidget::class)
            ->assertSee("Solde aujourd'hui")->assertSee('Solde hier')
            ->assertSee('13 545 550 FCFA')
            ->assertSee('Journée de caisse : 08h30 à 08h29');

        Carbon::setTestNow(Carbon::parse('2026-09-26 08:30:00', CAISSE_TZ));
        $service = app(CaisseService::class);
        expect($service->soldeAujourdhui())->toBe('0.00')
            ->and($service->soldeHier())->toBe('13545550.00');

        Livewire::test(CaisseStatsWidget::class)
            ->assertSee('0 FCFA')->assertSee('13 545 550 FCFA')->assertSee('25 septembre 2026')
            ->assertDontSee('Total entrées');
    });

    it('ne supprime ni ne modifie les anciens mouvements au changement de journée', function () {
        caisseAdmin();
        $m = caisseAt('2026-09-25 12:00:00', 'entree', 1000);

        Carbon::setTestNow(Carbon::parse('2026-09-26 09:00:00', CAISSE_TZ));
        app(CaisseService::class)->soldeAujourdhui();

        expect($m->fresh()->amount)->toBe('1000.00')->and(CaisseMouvement::count())->toBe(1);
    });
});

describe('Catégories de caisse', function () {
    it('initialise les catégories par défaut sans doublon', function () {
        expect(CaisseCategorie::pluck('name')->all())
            ->toContain('Espèce', 'UV Djamo', 'UV Orange Money', 'UV Push', 'Wave', 'Non catégorisé')
            ->and(CaisseCategorie::count())->toBe(6);
    });

    it('crée une catégorie et refuse un doublon (casse et espaces ignorés)', function () {
        $this->actingAs(caisseAdmin());

        Livewire::test(ManageCaisseCategories::class)
            ->callAction('create', data: ['name' => 'Moov Money', 'color' => '#ff0000', 'sort_order' => 7, 'is_active' => true])
            ->assertHasNoActionErrors();

        expect(CaisseCategorie::where('name', 'Moov Money')->exists())->toBeTrue();

        Livewire::test(ManageCaisseCategories::class)
            ->callAction('create', data: ['name' => '  moov   MONEY ', 'sort_order' => 1, 'is_active' => true])
            ->assertHasActionErrors(['name']);

        expect(CaisseCategorie::nameIsTaken('WAVE'))->toBeTrue()
            ->and(CaisseCategorie::nameIsTaken('Wave', cat('Wave')->id))->toBeFalse();
    });

    it('modifie nom, couleur et ordre', function () {
        $this->actingAs(caisseAdmin());
        $wave = cat('Wave');

        Livewire::test(ManageCaisseCategories::class)
            ->callTableAction('edit', $wave, data: ['name' => 'Wave CI', 'color' => '#00aaff', 'sort_order' => 3, 'is_active' => true])
            ->assertHasNoTableActionErrors();

        expect($wave->fresh())->name->toBe('Wave CI')->color->toBe('#00aaff')->sort_order->toBe(3);
    });

    it('désactive (avec confirmation) puis réactive ; la catégorie inactive disparaît des formulaires', function () {
        $this->actingAs(caisseAdmin());
        $wave = cat('Wave');

        Livewire::test(ManageCaisseCategories::class)->callTableAction('deactivate', $wave->fresh());
        expect($wave->fresh()->is_active)->toBeFalse()
            ->and(MaCaissePage::activeCategoryOptions())->not->toContain('Wave');

        Livewire::test(ManageCaisseCategories::class)->callTableAction('activate', $wave->fresh());
        expect($wave->fresh()->is_active)->toBeTrue()
            ->and(MaCaissePage::activeCategoryOptions())->toContain('Wave');
    });

    it('refuse de supprimer une catégorie utilisée ou système', function () {
        $this->actingAs(caisseAdmin());
        $wave = cat('Wave');
        caisseAt('2026-09-25 12:00:00', 'entree', 1000, $wave);

        expect($wave->delete())->toBeFalse()
            ->and(CaisseCategorie::whereKey($wave->id)->exists())->toBeTrue()
            ->and(CaisseCategorie::where('is_system', true)->first()->delete())->toBeFalse();

        $unused = cat('UV Push');
        expect($unused->delete())->toBeTrue();
    });

    it('rejette un mouvement sur une catégorie inactive', function () {
        $this->actingAs(caisseAdmin());
        $wave = cat('Wave');
        $wave->update(['is_active' => false]);

        expect(fn () => app(CaisseService::class)->enregistrerLot([
            ['type' => 'entree', 'caisse_categorie_id' => $wave->id, 'libelle' => 'x', 'amount' => 1000],
        ]))->toThrow(ValidationException::class);
    });
});

describe('Enregistrement de plusieurs mouvements', function () {
    it('enregistre toutes les lignes ensemble', function () {
        $this->actingAs(caisseAdmin());

        $created = app(CaisseService::class)->enregistrerLot([
            ['type' => 'entree', 'caisse_categorie_id' => cat('Espèce')->id, 'libelle' => 'Recette', 'amount' => 4020000],
            ['type' => 'sortie', 'caisse_categorie_id' => cat('Wave')->id, 'libelle' => 'Transfert', 'amount' => 20000],
        ]);

        expect($created)->toHaveCount(2)->and(CaisseMouvement::count())->toBe(2)
            ->and(app(CaisseService::class)->soldeAujourdhui())->toBe('4000000.00');
    });

    it('annule tout si une ligne est invalide (rollback complet)', function () {
        $this->actingAs(caisseAdmin());

        expect(fn () => app(CaisseService::class)->enregistrerLot([
            ['type' => 'entree', 'caisse_categorie_id' => cat('Espèce')->id, 'libelle' => 'Valide', 'amount' => 1000],
            ['type' => 'entree', 'caisse_categorie_id' => cat('Espèce')->id, 'libelle' => 'Invalide', 'amount' => 0],
        ]))->toThrow(ValidationException::class);

        expect(CaisseMouvement::count())->toBe(0);
    });

    it('annule tout si une écriture échoue en base (transaction atomique)', function () {
        $this->actingAs(caisseAdmin());
        $espece = cat('Espèce');

        CaisseMouvement::creating(function (CaisseMouvement $m) {
            if ($m->libelle === 'Boom') {
                throw new RuntimeException('échec simulé');
            }
        });

        expect(fn () => app(CaisseService::class)->enregistrerLot([
            ['type' => 'entree', 'caisse_categorie_id' => $espece->id, 'libelle' => 'Ok', 'amount' => 1000],
            ['type' => 'entree', 'caisse_categorie_id' => $espece->id, 'libelle' => 'Boom', 'amount' => 1000],
        ]))->toThrow(RuntimeException::class);

        expect(CaisseMouvement::count())->toBe(0);
    });

    it('refuse des sorties supérieures au solde disponible, entrées du lot comprises', function () {
        $this->actingAs(caisseAdmin());
        $espece = cat('Espèce')->id;

        expect(fn () => app(CaisseService::class)->enregistrerLot([
            ['type' => 'sortie', 'caisse_categorie_id' => $espece, 'libelle' => 'Trop', 'amount' => 5000],
        ]))->toThrow(ValidationException::class);

        app(CaisseService::class)->enregistrerLot([
            ['type' => 'sortie', 'caisse_categorie_id' => $espece, 'libelle' => 'Couvert par le lot', 'amount' => 5000],
            ['type' => 'entree', 'caisse_categorie_id' => $espece, 'libelle' => 'Entrée', 'amount' => 6000],
        ]);

        expect(CaisseMouvement::count())->toBe(2);
    });

    it('via la modale "Ajouter un mouvement" de la page', function () {
        $this->actingAs(caisseAdmin());

        $page = Livewire::test(MaCaissePage::class)->mountAction('add');
        $defaultKey = array_key_first($page->instance()->mountedActionsData[0]['mouvements']);

        $page->setActionData([
            'mouvements' => [
                $defaultKey => ['type' => 'entree', 'caisse_categorie_id' => cat('Espèce')->id, 'libelle' => 'Recette de la journée', 'amount' => '4 020 000'],
                'second' => ['type' => 'entree', 'caisse_categorie_id' => cat('Wave')->id, 'libelle' => 'Dépôt Wave', 'amount' => '75000'],
            ],
            'caisse_date' => app(CaissePeriodResolver::class)->currentDate()->toDateString(),
        ])->callMountedAction()->assertHasNoActionErrors();

        expect(CaisseMouvement::count())->toBe(2)
            ->and((string) CaisseMouvement::where('libelle', 'Recette de la journée')->first()->amount)->toBe('4020000.00');
    });

    it('génère une référence courte préfixée C', function () {
        $this->actingAs(caisseAdmin());
        $m = app(CaisseService::class)->enregistrer('entree', 100000, 'Test');

        expect($m->reference)->toMatch('/^C-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/')
            ->and($m->categorie->is_system)->toBeTrue();
    });
});

describe('Calculs', function () {
    it('calcule entrées, sorties, solde du jour et solde par catégorie', function () {
        $this->actingAs(caisseAdmin());
        caisseAt('2026-09-25 09:00:00', 'entree', 8545550, cat('Espèce'));
        caisseAt('2026-09-25 10:00:00', 'sortie', 5000000, cat('Espèce'));
        caisseAt('2026-09-25 11:00:00', 'entree', 1000, cat('Wave'));

        $service = app(CaisseService::class);
        $day = Carbon::parse('2026-09-25', CAISSE_TZ);

        expect($service->dayTotals($day))->toMatchArray(['count' => 3, 'entrees' => '8546550.00', 'sorties' => '5000000.00', 'solde' => '3546550.00']);

        $byCat = $service->categoryBalances($day, $day)->keyBy('name');
        expect($byCat['Espèce']['solde'])->toBe('3545550.00')
            ->and($byCat['Wave']['solde'])->toBe('1000.00');
    });

    it('calcule le solde après mouvement dans l\'ordre chronologique exact', function () {
        $this->actingAs(caisseAdmin());
        $b = caisseAt('2026-09-25 11:00:00', 'sortie', 200);
        $a = caisseAt('2026-09-25 09:00:00', 'entree', 1000);
        $c = caisseAt('2026-09-25 12:00:00', 'entree', 50);

        $map = app(CaisseService::class)->dayRunningBalances(Carbon::parse('2026-09-25', CAISSE_TZ));

        expect($map[$a->id]['solde_apres'])->toBe('1000.00')
            ->and($map[$b->id]['solde_apres'])->toBe('800.00')
            ->and($map[$c->id]['solde_apres'])->toBe('850.00');
    });
});

describe('Historique par journée', function () {
    it('regroupe par journée, plus récente d\'abord, avec recherche et période', function () {
        $this->actingAs(caisseAdmin());
        caisseAt('2026-09-25 12:00:00', 'entree', 1000);
        caisseAt('2026-09-25 13:00:00', 'sortie', 400);
        caisseAt('2026-09-24 12:00:00', 'entree', 300);
        caisseAt('2026-09-20 12:00:00', 'entree', 100);

        $service = app(CaisseService::class);
        $all = $service->daySummaries();

        expect($all->pluck('date')->map->toDateString()->all())->toBe(['2026-09-25', '2026-09-24', '2026-09-20'])
            ->and($all[0])->toMatchArray(['count' => 2, 'solde' => '600.00'])
            ->and($service->daySummaries(null, null, '24/09')->count())->toBe(1)
            ->and($service->daySummaries(Carbon::parse('2026-09-24', CAISSE_TZ), Carbon::parse('2026-09-25', CAISSE_TZ))->count())->toBe(2);
    });

    it('affiche la page avec filtres, montants formatés et pagination', function () {
        $this->actingAs(caisseAdmin());
        foreach (range(1, 12) as $i) {
            caisseAt(Carbon::parse('2026-09-01 12:00', CAISSE_TZ)->addDays($i)->toDateTimeString(), 'entree', 1000 * $i);
        }
        caisseAt('2026-09-13 13:00:00', 'sortie', 500);

        Livewire::test(MaCaissePage::class)
            ->assertSee('Voir les détails')
            ->assertSee('13/09/2026')
            ->assertDontSee('02/09/2026')
            ->call('nextPage')
            ->assertSee('02/09/2026')
            ->set('search', '13/09')
            ->assertSee('13/09/2026')
            ->assertSee('12 000 FCFA')
            ->assertDontSee('02/09/2026');
    });

    it('affiche un état vide clair', function () {
        $this->actingAs(caisseAdmin());
        Livewire::test(MaCaissePage::class)->assertSee('Aucun mouvement de caisse');
    });
});

describe('Détails d\'une journée', function () {
    it('liste les mouvements, filtre et affiche les soldes par catégorie', function () {
        $admin = caisseAdmin();
        $this->actingAs($admin);
        caisseAt('2026-09-25 09:00:00', 'entree', 4020000, cat('Espèce'), 'Recette de la journée');
        caisseAt('2026-09-25 10:00:00', 'sortie', 20000, cat('Wave'), 'Frais Wave');

        Livewire::test(MaCaisseJourPage::class, ['date' => '2026-09-25'])
            ->assertSee('Recette de la journée')->assertSee('Frais Wave')
            ->assertSee('Soldes par catégorie')->assertSee('4 020 000 FCFA')
            ->filterTable('type', 'sortie')
            ->assertSee('Frais Wave')->assertDontSee('Recette de la journée')
            ->resetTableFilters()
            ->filterTable('caisse_categorie_id', cat('Espèce')->id)
            ->assertSee('Recette de la journée')->assertDontSee('Frais Wave')
            ->resetTableFilters()
            ->searchTable('Frais')
            ->assertSee('Frais Wave')->assertDontSee('Recette de la journée');

        $this->get(MaCaisseJourPage::getUrl(['date' => '2026-09-25']))->assertOk();
    });
});

describe('Export PDF', function () {
    it('exporte l\'historique et le détail d\'une journée en PDF valide', function () {
        $this->actingAs(caisseAdmin());
        caisseAt('2026-09-25 09:00:00', 'entree', 4020000, cat('Espèce'), "Recette N'Guessan");

        Livewire::test(MaCaissePage::class)->callAction('exportPdf')->assertFileDownloaded();
        Livewire::test(MaCaisseJourPage::class, ['date' => '2026-09-25'])->callAction('exportPdf')->assertFileDownloaded();
    });
});

describe('Permissions et compatibilité', function () {
    it('refuse l\'accès sans view_caisse', function () {
        $user = User::factory()->create();
        $user->syncRoles([]);
        $user->syncPermissions([]);
        $this->actingAs($user);

        expect(MaCaissePage::canAccess())->toBeFalse()->and(MaCaisseJourPage::canAccess())->toBeFalse();
    });

    it('rattache les anciens mouvements sans catégorie à "Non catégorisé"', function () {
        $this->actingAs(caisseAdmin());
        $m = app(CaisseService::class)->enregistrer('entree', 100, 'Ancien');

        expect($m->categorie->name)->toBe('Non catégorisé');
    });
});
