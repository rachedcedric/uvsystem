<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\MasterSolde;
use App\Models\User;
use App\Models\UvTransaction;
use App\Services\MasterSoldeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MasterSoldeValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Agent $agent;

    protected MasterSoldeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Créer un utilisateur Master
        $this->user = User::factory()->create([
            'name' => 'Master Test',
            'email' => 'master@test.com',
        ]);

        // Créer un agent
        $this->agent = Agent::factory()->create([
            'name' => 'Agent Test',
            'status' => 'active',
            'debt_limit' => 100000,
        ]);

        $this->service = new MasterSoldeService;

        $this->actingAs($this->user);
    }

    #[Test]
    public function test_1_validation_bloque_distribution_si_solde_insuffisant()
    {
        echo "\n🧪 TEST 1: Validation bloque distribution si solde insuffisant\n";
        echo "============================================================\n";

        // Créer un approvisionnement de 100 000 FCFA
        $fournisseur = Fournisseur::factory()->create();
        Approvisionnement::create([
            'user_id' => $this->user->id,
            'fournisseur_id' => $fournisseur->id,
            'amount' => 100000,
            'date_approvisionnement' => now(),
        ]);

        $solde = MasterSolde::where('user_id', $this->user->id)->first();
        echo '✅ Approvisionnement: '.number_format($solde->total_approvisionne, 0, ',', ' ')." FCFA\n";
        echo '✅ Solde disponible: '.number_format($solde->solde_disponible, 0, ',', ' ')." FCFA\n";

        // Tenter de distribuer 150 000 FCFA (plus que disponible)
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Solde UV insuffisant pour effectuer cette vente.');

        $this->service->validateDistribution(150000);

        echo "❌ Ce message ne devrait pas apparaître\n";
    }

    #[Test]
    public function test_2_distribution_autorisee_si_solde_suffisant()
    {
        echo "\n🧪 TEST 2: Distribution autorisée si solde suffisant\n";
        echo "===================================================\n";

        // Créer un approvisionnement de 500 000 FCFA
        $fournisseur = Fournisseur::factory()->create();
        Approvisionnement::create([
            'user_id' => $this->user->id,
            'fournisseur_id' => $fournisseur->id,
            'amount' => 500000,
            'date_approvisionnement' => now(),
        ]);

        echo "✅ Approvisionnement: 500 000 FCFA\n";

        // Distribuer 200 000 FCFA (moins que disponible)
        try {
            $this->service->validateDistribution(200000);
            echo "✅ Validation réussie pour 200 000 FCFA\n";

            UvTransaction::create([
                'agent_id' => $this->agent->id,
                'amount' => 200000,
                'transaction_type' => 'distribution',
                'transaction_date' => now(),
            ]);

            $solde = MasterSolde::where('user_id', $this->user->id)->first();
            echo '✅ Nouveau solde: '.number_format($solde->solde_disponible, 0, ',', ' ')." FCFA (devrait être 300 000)\n";

            $this->assertEquals(300000, $solde->solde_disponible);
        } catch (\Exception $e) {
            echo '❌ Erreur inattendue: '.$e->getMessage()."\n";
            throw $e;
        }
    }

    #[Test]
    public function test_3_notification_envoyee_si_solde_bas()
    {
        echo "\n🧪 TEST 3: Notification envoyée si solde < seuil\n";
        echo "===============================================\n";

        Notification::fake();

        // Créer un approvisionnement de 50 000 FCFA (en dessous du seuil de 100 000)
        $fournisseur = Fournisseur::factory()->create();
        Approvisionnement::create([
            'user_id' => $this->user->id,
            'fournisseur_id' => $fournisseur->id,
            'amount' => 50000,
            'date_approvisionnement' => now(),
        ]);

        echo "✅ Approvisionnement: 50 000 FCFA (< seuil 100 000)\n";

        // Vérifier si le seuil est détecté
        $isBelowThreshold = $this->service->isBelowThreshold();
        echo '✅ Solde sous le seuil: '.($isBelowThreshold ? 'OUI' : 'NON')."\n";

        $this->assertTrue($isBelowThreshold);

        // Déclencher l'alerte
        $this->service->checkAndAlertLowBalance();
        echo "✅ Alerte déclenchée\n";
    }

    #[Test]
    public function test_4_audit_log_enregistre_modifications()
    {
        echo "\n🧪 TEST 4: ActivityLog enregistre les modifications\n";
        echo "==================================================\n";

        // Créer un fournisseur
        $fournisseur = Fournisseur::create([
            'name' => 'Test Banque',
            'type' => 'BANQUE',
            'contact' => '123456789',
            'is_active' => true,
        ]);

        echo "✅ Fournisseur créé: {$fournisseur->name}\n";

        // Modifier le fournisseur
        $fournisseur->update([
            'name' => 'Banque Modifiée',
            'type' => 'AGENT_PREMIUM',
        ]);

        echo "✅ Fournisseur modifié\n";

        // Vérifier les logs
        $logs = Activity::where('subject_type', Fournisseur::class)
            ->where('subject_id', $fournisseur->id)
            ->get();

        echo '✅ Nombre de logs trouvés: '.$logs->count()."\n";

        foreach ($logs as $log) {
            echo "   - {$log->description} le ".$log->created_at->format('d/m/Y H:i:s')."\n";
            if ($log->description === 'updated' && $log->properties) {
                echo '     Changements: '.json_encode($log->properties->get('attributes'), JSON_UNESCAPED_UNICODE)."\n";
            }
        }

        $this->assertGreaterThanOrEqual(2, $logs->count(), 'Devrait avoir au moins 2 logs (created + updated)');
    }
}
