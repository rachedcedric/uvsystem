<?php

use App\Filament\Pages\DettesFournisseursPage;
use App\Filament\Resources\RemboursementFournisseurResource;
use App\Filament\Resources\RemboursementFournisseurResource\Pages\CreateRemboursementFournisseur;
use App\Filament\Widgets\FournisseurDebtStatsWidget;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\PaymentMethod;
use App\Models\RemboursementFournisseur;
use App\Models\User;
use App\Services\FournisseurDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function fournisseurDebtAdmin(): User
{
    foreach ([
        'view_remboursements_fournisseurs',
        'create_remboursements_fournisseurs',
        'edit_remboursements_fournisseurs',
        'view_approvisionnements',
        'create_approvisionnements',
        'view_fournisseurs',
    ] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
    $role->givePermissionTo([
        'view_remboursements_fournisseurs',
        'create_remboursements_fournisseurs',
        'edit_remboursements_fournisseurs',
        'view_approvisionnements',
        'create_approvisionnements',
        'view_fournisseurs',
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('master');

    return $admin;
}

function creditFournisseur(string $name = 'Grossiste Premium'): Fournisseur
{
    return Fournisseur::factory()->create(['name' => $name, 'type' => 'AGENT_PREMIUM', 'is_active' => true]);
}

function approvisionnerFournisseur(Fournisseur $fournisseur, User $user, float $amount): Approvisionnement
{
    return Approvisionnement::create([
        'user_id' => $user->id,
        'fournisseur_id' => $fournisseur->id,
        'amount' => $amount,
        'date_approvisionnement' => now(),
    ]);
}

describe('FournisseurDebtService — single source of truth', function () {
    it('accrues debt for a credit (Agent Premium) supplier on every approvisionnement', function () {
        $admin = fournisseurDebtAdmin();
        $fournisseur = creditFournisseur();

        approvisionnerFournisseur($fournisseur, $admin, 500_000);
        approvisionnerFournisseur($fournisseur, $admin, 300_000);

        $service = app(FournisseurDebtService::class);
        $totaux = $service->soldeTotals($fournisseur->fresh());

        expect($totaux['distribue'])->toBe(800_000.0)
            ->and($totaux['rembourse'])->toBe(0.0)
            ->and($service->detteRestante($fournisseur->fresh()))->toBe(800_000.0);
    });

    it('reduces the remaining debt after a repayment, via process()', function () {
        $admin = fournisseurDebtAdmin();
        $fournisseur = creditFournisseur();
        approvisionnerFournisseur($fournisseur, $admin, 500_000);

        $this->actingAs($admin);
        $remboursement = app(FournisseurDebtService::class)->process($fournisseur, 200_000, 'cash');

        expect($remboursement->reference)->toMatch('/^R-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/')
            ->and(app(FournisseurDebtService::class)->detteRestante($fournisseur->fresh()))->toBe(300_000.0);
    });

    it('rejects a repayment exceeding the remaining debt', function () {
        $fournisseur = creditFournisseur();
        $admin = fournisseurDebtAdmin();
        approvisionnerFournisseur($fournisseur, $admin, 100_000);

        expect(fn () => app(FournisseurDebtService::class)->process($fournisseur, 150_000, 'cash'))
            ->toThrow(ValidationException::class);
    });

    it('never accrues debt for a "Banque" (prepaid) supplier', function () {
        $admin = fournisseurDebtAdmin();
        $banque = Fournisseur::factory()->create(['name' => 'Banque Locale', 'type' => 'BANQUE', 'is_active' => true]);
        approvisionnerFournisseur($banque, $admin, 1_000_000);

        // Hors du périmètre crédit : jamais inclus dans le résumé global.
        $summary = app(FournisseurDebtService::class)->globalSummary();

        expect($summary['totalDette'])->toBe(0.0);
    });

    it('computes the global summary across all credit suppliers, matching the sum of each one', function () {
        $admin = fournisseurDebtAdmin();
        $a = creditFournisseur('Fournisseur A');
        $b = creditFournisseur('Fournisseur B');
        approvisionnerFournisseur($a, $admin, 400_000);
        approvisionnerFournisseur($b, $admin, 600_000);

        $this->actingAs($admin);
        app(FournisseurDebtService::class)->process($a, 100_000, 'cash');

        $summary = app(FournisseurDebtService::class)->globalSummary();

        expect($summary['totalDette'])->toBe(1_000_000.0)
            ->and($summary['totalRembourse'])->toBe(100_000.0)
            ->and($summary['resteAPayer'])->toBe(900_000.0);
    });
});

describe('DettesFournisseursPage and FournisseurDebtStatsWidget', function () {
    it('shows the 3 required widgets with correct values', function () {
        $admin = fournisseurDebtAdmin();
        $this->actingAs($admin);
        $fournisseur = creditFournisseur();
        approvisionnerFournisseur($fournisseur, $admin, 500_000);
        app(FournisseurDebtService::class)->process($fournisseur, 200_000, 'cash');

        Livewire::test(FournisseurDebtStatsWidget::class)
            ->assertSuccessful()
            ->assertSee('Dette actuelle')
            ->assertSee('Déjà remboursée')
            ->assertSee('Dette restante')
            ->assertSee('500 000 FCFA')
            ->assertSee('200 000 FCFA')
            ->assertSee('300 000 FCFA');
    });

    it('lists credit suppliers with a Payer action linking to the create form', function () {
        $admin = fournisseurDebtAdmin();
        $this->actingAs($admin);
        $fournisseur = creditFournisseur('Grossiste XYZ');
        approvisionnerFournisseur($fournisseur, $admin, 250_000);

        $html = Livewire::test(DettesFournisseursPage::class)->html();

        expect($html)->toContain('Grossiste XYZ')
            ->toContain(RemboursementFournisseurResource::getUrl('create', ['fournisseur_id' => $fournisseur->id]));
    });

    it('denies access without the view_remboursements_fournisseurs permission', function () {
        $user = User::factory()->create();
        $user->syncRoles([]);
        $user->syncPermissions([]);

        expect(DettesFournisseursPage::canAccess())->toBeFalse();
    });
});

describe('CreateRemboursementFournisseur — preselection and reuse of registered payment methods', function () {
    it('preselects the supplier from the fournisseur_id query parameter', function () {
        $admin = fournisseurDebtAdmin();
        $this->actingAs($admin);
        $fournisseur = creditFournisseur('KOFFI Grossiste');
        approvisionnerFournisseur($fournisseur, $admin, 100_000);

        $response = $this->get('/master/remboursements-fournisseurs/create?fournisseur_id='.$fournisseur->id);

        $response->assertOk();
        $response->assertSee('KOFFI Grossiste');
    });

    it('creates a repayment record with an existing PaymentMethod code', function () {
        $admin = fournisseurDebtAdmin();
        $this->actingAs($admin);
        $fournisseur = creditFournisseur();
        approvisionnerFournisseur($fournisseur, $admin, 100_000);

        PaymentMethod::firstOrCreate(['code' => 'cash'], ['name' => 'Espèces', 'is_active' => true, 'sort_order' => 1]);

        Livewire::test(CreateRemboursementFournisseur::class)
            ->fillForm([
                'fournisseur_id' => $fournisseur->id,
                'amount' => '50000',
                'payment_method' => 'cash',
                'payment_date' => now()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(RemboursementFournisseur::where('fournisseur_id', $fournisseur->id)->count())->toBe(1)
            ->and(RemboursementFournisseur::first()->payment_method)->toBe('cash');
    });
});
