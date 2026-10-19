<?php

namespace Tests\Feature;

use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\User;
use App\Services\Mobile\HomeDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileHomeActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_recent_supply_displays_the_supplier_name(): void
    {
        $user = User::factory()->create(['name' => 'Utilisateur de saisie']);
        $supplier = Fournisseur::factory()->create(['name' => 'Fournisseur Mobile']);

        Approvisionnement::create([
            'user_id' => $user->id,
            'fournisseur_id' => $supplier->id,
            'amount' => 300000,
            'date_approvisionnement' => now(),
        ]);

        $activity = app(HomeDashboard::class)->recentActivity();

        $this->assertSame('Fournisseur Mobile', $activity[0]['tiers']);
    }
}
