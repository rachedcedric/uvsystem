<?php

use App\Filament\Resources\UvTransactionResource\Pages\CreateUvTransaction;
use App\Filament\Resources\UvTransactionResource\Pages\EditUvTransaction;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\User;
use App\Services\MasterSoldeService;
use App\Services\UvDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function retourUvAdmin(): User
{
    foreach (['view_uv_transactions', 'create_uv_transactions', 'edit_uv_transactions'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
    $role->givePermissionTo(['view_uv_transactions', 'create_uv_transactions', 'edit_uv_transactions']);

    $admin = User::factory()->create();
    $admin->assignRole('master');

    return $admin;
}

describe('Masquage UI de "Retour" à la création (backend intact)', function () {
    it('does not offer the "Retour" option on /master/uv-transactions/create, only "Distribution"', function () {
        $admin = retourUvAdmin();
        $this->actingAs($admin);

        $html = html_entity_decode(Livewire::test(CreateUvTransaction::class)->html());

        expect($html)->toContain('Distribution (Vente UV à l\'agent)')
            ->not->toContain('Retour (Reprise UV de l\'agent)');
    });

    it('still displays the "Retour" option when editing an existing historical retour transaction', function () {
        $admin = retourUvAdmin();
        $this->actingAs($admin);
        $fournisseur = Fournisseur::factory()->create();

        Approvisionnement::create([
            'user_id' => $admin->id,
            'fournisseur_id' => $fournisseur->id,
            'amount' => 1_000_000,
            'date_approvisionnement' => now(),
        ]);

        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        $service = app(UvDistributionService::class);
        $service->distribute($agent, 200_000, $fournisseur);
        $retour = $service->processReturn($agent, 50_000);

        $html = html_entity_decode(Livewire::test(EditUvTransaction::class, ['record' => $retour->getRouteKey()])->html());

        expect($html)->toContain('Retour (Reprise UV de l\'agent)');
    });

    it('keeps UvDistributionService::processReturn() fully functional (backend logic untouched)', function () {
        $admin = retourUvAdmin();
        $this->actingAs($admin);
        $fournisseur = Fournisseur::factory()->create();

        Approvisionnement::create([
            'user_id' => $admin->id,
            'fournisseur_id' => $fournisseur->id,
            'amount' => 1_000_000,
            'date_approvisionnement' => now(),
        ]);

        $agent = Agent::factory()->create(['status' => 'active', 'debt_limit' => 0]);
        $service = app(UvDistributionService::class);
        $service->distribute($agent, 200_000, $fournisseur);
        $retour = $service->processReturn($agent, 50_000);

        expect($retour->transaction_type)->toBe('retour')
            ->and((float) $retour->amount)->toBe(50_000.0)
            ->and(app(MasterSoldeService::class)->getAvailableBalance())->toBe(850_000.0);
    });
});
