<?php

use App\Filament\Resources\RemboursementResource\Pages\ListRemboursements;
use App\Filament\Resources\UvTransactionResource\Pages\ListUvTransactions;
use App\Models\Agent;
use App\Models\Remboursement;
use App\Models\User;
use App\Models\UvTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function referenceSearchAdmin(): User
{
    foreach (['view_uv_transactions', 'view_remboursements'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
    $role->givePermissionTo(['view_uv_transactions', 'view_remboursements']);

    $admin = User::factory()->create();
    $admin->assignRole('master');

    return $admin;
}

describe('Reference search — old and new formats coexist', function () {
    it('finds a UvTransaction by its legacy "UV-..." reference and by its new "V-XXXXXX" reference', function () {
        $admin = referenceSearchAdmin();
        $this->actingAs($admin);
        $agent = Agent::factory()->create(['status' => 'active']);

        // Ancienne référence : insérée directement (le générateur court ne
        // s'applique qu'aux nouvelles créations, jamais en écrasant l'existant).
        DB::table('uv_transactions')->insert([
            'agent_id' => $agent->id,
            'amount' => 100_000,
            'transaction_type' => 'distribution',
            'reference' => 'UV-LEGACY123XYZ',
            'transaction_date' => now(),
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Nouvelle référence : générée par le modèle lui-même.
        $nouvelle = UvTransaction::create([
            'agent_id' => $agent->id,
            'amount' => 50_000,
            'transaction_type' => 'distribution',
            'transaction_date' => now(),
            'status' => 'completed',
        ]);

        expect($nouvelle->reference)->toMatch('/^V-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/');

        Livewire::test(ListUvTransactions::class)
            ->searchTable('UV-LEGACY123XYZ')
            ->assertCanSeeTableRecords(UvTransaction::where('reference', 'UV-LEGACY123XYZ')->get())
            ->assertCanNotSeeTableRecords(UvTransaction::where('reference', $nouvelle->reference)->get());

        Livewire::test(ListUvTransactions::class)
            ->searchTable($nouvelle->reference)
            ->assertCanSeeTableRecords(UvTransaction::where('reference', $nouvelle->reference)->get())
            ->assertCanNotSeeTableRecords(UvTransaction::where('reference', 'UV-LEGACY123XYZ')->get());
    });

    it('finds a Remboursement by its legacy "RMB-..." reference and by its new "R-XXXXXX" reference', function () {
        $admin = referenceSearchAdmin();
        $this->actingAs($admin);
        $agent = Agent::factory()->create(['status' => 'active']);

        DB::table('remboursements')->insert([
            'agent_id' => $agent->id,
            'amount' => 20_000,
            'payment_method' => 'cash',
            'reference' => 'RMB-LEGACY987',
            'payment_date' => now(),
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $nouveau = Remboursement::create([
            'agent_id' => $agent->id,
            'amount' => 10_000,
            'payment_method' => 'cash',
            'payment_date' => now(),
        ]);

        expect($nouveau->reference)->toMatch('/^R-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/');

        Livewire::test(ListRemboursements::class)
            ->searchTable('RMB-LEGACY987')
            ->assertCanSeeTableRecords(Remboursement::where('reference', 'RMB-LEGACY987')->get());

        Livewire::test(ListRemboursements::class)
            ->searchTable($nouveau->reference)
            ->assertCanSeeTableRecords(Remboursement::where('reference', $nouveau->reference)->get());
    });
});
