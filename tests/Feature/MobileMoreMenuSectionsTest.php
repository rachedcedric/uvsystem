<?php

namespace Tests\Feature;

use App\Livewire\Mobile\More;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Menu « Plus » : rubriques Argent, Analyse, Configuration, Entreprise, Mon compte, puis préférences. */
class MobileMoreMenuSectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->master = User::factory()->create();
        $this->master->assignRole('master');
    }

    /** @return list<string> libellés dans l'ordre d'apparition dans le HTML */
    private function order(string $html, array $labels): array
    {
        $positions = [];
        foreach ($labels as $label) {
            $pos = mb_strpos($html, $label);
            $this->assertNotFalse($pos, "« {$label} » est absent du menu Plus");
            $positions[$label] = $pos;
        }
        asort($positions);

        return array_keys($positions);
    }

    public function test_sections_and_their_screens_are_in_the_proposed_order(): void
    {
        $html = Livewire::actingAs($this->master)->test(More::class)->html();

        $expected = [
            'Argent', 'Caisse', 'Remboursements', 'Approvisionnements', 'Charges', 'Commissions',
            'Analyse', 'Statistiques', 'Scoring des agents', 'Rapports et exports', 'Journal d\'activité',
            'Configuration', 'Fournisseurs', 'Modes de paiement', 'Catégories de charges', 'Catégories de caisse', 'Paliers',
            'Entreprise', 'Utilisateurs', 'Profil de l\'entreprise', 'Mon abonnement', 'Import de données',
            'Mon compte', 'Notifications', 'Sécurité du compte', 'Diagnostic des notifications',
            'Préférences',
        ];
        // `htmlspecialchars` : l'apostrophe est échappée dans le HTML rendu.
        // Les titres de rubrique sont repérés par leur balise (« Entreprise » apparaît aussi en en-tête de page).
        $titles = ['Argent', 'Analyse', 'Configuration', 'Entreprise', 'Mon compte', 'Préférences'];
        $encoded = array_map(fn (string $l): string => in_array($l, $titles, true) ? '>'.e($l).'</h2>' : e($l), $expected);

        $this->assertSame($encoded, $this->order($html, $encoded));
        $this->assertStringContainsString('Se déconnecter', mb_substr($html, mb_strpos($html, 'Préférences')));
    }

    public function test_configuration_and_company_are_folded_but_the_others_are_not(): void
    {
        $html = Livewire::actingAs($this->master)->test(More::class)->html();

        preg_match_all('/<details class="m-fold"[^>]*>\s*<summary[^>]*>\s*<h2[^>]*>([^<]+)<\/h2>/', $html, $folded);
        $this->assertSame(['Configuration', 'Entreprise'], $folded[1]);
        $this->assertDoesNotMatchRegularExpression('/<details class="m-fold"[^>]*\sopen/', $html);
    }

    public function test_searching_opens_the_folded_sections_and_hides_preferences(): void
    {
        $html = Livewire::actingAs($this->master)->test(More::class)->set('search', 'paliers')->html();

        $this->assertMatchesRegularExpression('/<details class="m-fold"[^>]*\sopen/', $html);
        $this->assertStringContainsString('Paliers', $html);
        $this->assertStringNotContainsString('Sons des touches', $html);
        $this->assertStringNotContainsString('Mon abonnement', $html);
    }

    public function test_empty_sections_disappear_and_nothing_is_offered_without_the_right(): void
    {
        $limited = User::factory()->create();
        $limited->assignRole('commercial');

        $html = Livewire::actingAs($limited)->test(More::class)->html();

        $this->assertStringNotContainsString('Sauvegardes', $html);
        $this->assertStringNotContainsString('Import de données', $html);
        $this->assertStringContainsString('Mon compte', $html);
    }
}
