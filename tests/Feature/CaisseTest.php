<?php

use App\Filament\Pages\MaCaissePage;
use App\Filament\Widgets\CaisseStatsWidget;
use App\Models\CaisseMouvement;
use App\Models\User;
use App\Services\CaisseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

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

describe('CaisseService — single source of truth', function () {
    it('accumulates entrées and sorties into a live-computed solde', function () {
        $admin = caisseAdmin();
        $this->actingAs($admin);
        $service = app(CaisseService::class);

        $service->enregistrer('entree', 500_000, 'Dépôt initial');
        $service->enregistrer('entree', 200_000, 'Vente comptant');
        $service->enregistrer('sortie', 150_000, 'Achat fournitures');

        $totals = $service->totals();

        expect($totals['entrees'])->toBe(700_000.0)
            ->and($totals['sorties'])->toBe(150_000.0)
            ->and($totals['solde'])->toBe(550_000.0)
            ->and($service->soldeActuel())->toBe(550_000.0);
    });

    it('generates a short C-prefixed reference', function () {
        $admin = caisseAdmin();
        $this->actingAs($admin);

        $mouvement = app(CaisseService::class)->enregistrer('entree', 100_000, 'Test');

        expect($mouvement->reference)->toMatch('/^C-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/');
    });

    it('rejects a sortie exceeding the current solde', function () {
        $admin = caisseAdmin();
        $this->actingAs($admin);
        $service = app(CaisseService::class);
        $service->enregistrer('entree', 100_000, 'Dépôt');

        expect(fn () => $service->enregistrer('sortie', 150_000, 'Retrait trop élevé'))
            ->toThrow(ValidationException::class);
    });

    it('rejects a zero or negative amount', function () {
        $admin = caisseAdmin();
        $this->actingAs($admin);
        $service = app(CaisseService::class);

        expect(fn () => $service->enregistrer('entree', 0, 'Invalide'))
            ->toThrow(ValidationException::class);
    });

    it('computes a correct running balance (solde avant/après) per movement, chronologically', function () {
        $admin = caisseAdmin();
        $this->actingAs($admin);
        $service = app(CaisseService::class);

        $e1 = $service->enregistrer('entree', 300_000, 'Premier dépôt', '2026-09-01 08:00:00');
        $e2 = $service->enregistrer('sortie', 100_000, 'Premier retrait', '2026-09-02 08:00:00');
        $e3 = $service->enregistrer('entree', 50_000, 'Second dépôt', '2026-09-03 08:00:00');

        $history = $service->historyWithRunningBalance()->keyBy(fn ($entry) => $entry['mouvement']->id);

        expect($history[$e1->id]['solde_avant'])->toBe(0.0)
            ->and($history[$e1->id]['solde_apres'])->toBe(300_000.0)
            ->and($history[$e2->id]['solde_avant'])->toBe(300_000.0)
            ->and($history[$e2->id]['solde_apres'])->toBe(200_000.0)
            ->and($history[$e3->id]['solde_avant'])->toBe(200_000.0)
            ->and($history[$e3->id]['solde_apres'])->toBe(250_000.0);
    });
});

describe('MaCaissePage and CaisseStatsWidget', function () {
    it('shows the 3 widgets with correct values', function () {
        $admin = caisseAdmin();
        $this->actingAs($admin);
        app(CaisseService::class)->enregistrer('entree', 400_000, 'Dépôt');
        app(CaisseService::class)->enregistrer('sortie', 100_000, 'Retrait');

        Livewire::test(CaisseStatsWidget::class)
            ->assertSuccessful()
            ->assertSee('Solde actuel')
            ->assertSee('Total entrées')
            ->assertSee('Total sorties')
            ->assertSee('300 000 FCFA')
            ->assertSee('400 000 FCFA')
            ->assertSee('100 000 FCFA');
    });

    it('lists movements with a libellé and the running solde après', function () {
        $admin = caisseAdmin();
        $this->actingAs($admin);
        app(CaisseService::class)->enregistrer('entree', 250_000, 'Dépôt espèces caisse');

        $html = Livewire::test(MaCaissePage::class)->html();

        expect($html)->toContain('Dépôt espèces caisse')
            ->toContain('250 000 FCFA');
    });

    it('denies access without the view_caisse permission', function () {
        $user = User::factory()->create();
        $user->syncRoles([]);
        $user->syncPermissions([]);

        expect(MaCaissePage::canAccess())->toBeFalse();
    });

    it('creates a movement via the "Ajouter un mouvement" header action', function () {
        $admin = caisseAdmin();
        $this->actingAs($admin);

        Livewire::test(MaCaissePage::class)
            ->callTableAction('add', data: [
                'type' => 'entree',
                'libelle' => 'Versement du jour',
                'amount' => '75000',
                'date_mouvement' => now()->format('Y-m-d H:i:s'),
            ]);

        expect(CaisseMouvement::where('libelle', 'Versement du jour')->count())->toBe(1)
            ->and((float) CaisseMouvement::where('libelle', 'Versement du jour')->first()->amount)->toBe(75000.0);
    });
});
