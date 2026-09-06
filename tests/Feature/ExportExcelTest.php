<?php

namespace Tests\Feature;

use App\Exports\ApprovisionnementExport;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportExcelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_export_excel_fonctionne()
    {
        echo "\n📊 TEST 5: Export Excel des approvisionnements\n";
        echo "============================================\n";

        // Créer des données
        $user = User::factory()->create();
        $fournisseur = Fournisseur::factory()->create([
            'name' => 'Banque Test Export',
        ]);

        Approvisionnement::create([
            'user_id' => $user->id,
            'fournisseur_id' => $fournisseur->id,
            'amount' => 50000,
            'date_approvisionnement' => now(),
            'notes' => 'Test export',
        ]);

        echo "✅ 1 approvisionnement créé\n";

        // Tester l'export
        $export = new ApprovisionnementExport;
        $data = $export->collection();

        echo '✅ Lignes à exporter: '.$data->count()."\n";
        $this->assertEquals(1, $data->count());

        // Tester les headings
        $headings = $export->headings();
        echo '✅ Nombre de colonnes: '.count($headings)."\n";
        $this->assertCount(8, $headings);

        // Tester le mapping
        $first = $data->first();
        $mapped = $export->map($first);

        echo "✅ Données mappées:\n";
        echo "   - Référence: {$mapped[0]}\n";
        echo "   - Fournisseur: {$mapped[2]}\n";
        echo "   - Montant: {$mapped[4]} FCFA\n";

        $this->assertEquals('Banque Test Export', $mapped[2]);
        $this->assertEquals('1,000.00', $mapped[4]);

        echo "✅ Export Excel validé avec succès!\n";
    }
}
