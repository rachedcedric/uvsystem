<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Home;
use App\Livewire\Mobile\Ventes;
use App\Models\User;
use App\Support\Mobile\Nav;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Accueil mobile (cartes cliquables, ordre), page Ventes (même widget que Remboursements) et onglet Dettes. */
class MobileHomeAndMenuLinksTest extends TestCase
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

    public function test_home_cards_are_in_the_requested_order_and_each_opens_its_page(): void
    {
        $html = $this->actingAs($this->master)->get('/m')->getContent();

        preg_match_all('/<a\s+href="([^"]+)"[^>]*class="m-stat"[^>]*>.*?<span>([^<]+)<\/span>/s', $html, $cards, PREG_SET_ORDER);
        $found = array_map(fn (array $c): array => [$c[2], parse_url($c[1], PHP_URL_PATH)], $cards);

        $this->assertSame([
            ['Ventes du jour', '/m/ventes'],
            ['Commission prévue', '/m/commissions'],
            ['Remboursements', '/m/remboursements'],
            ['Dettes en cours', '/m/dettes'],
            ['Approvisionnements', '/m/approvisionnements'],
        ], $found);
    }

    public function test_a_home_card_is_not_a_link_when_the_user_cannot_open_its_page(): void
    {
        $limited = User::factory()->create();
        $limited->givePermissionTo(['view_uv_transactions']);

        // Composant seul : l'accès au panneau (middleware) n'est pas l'objet de ce test.
        $html = Livewire::actingAs($limited)->test(Home::class)->html();

        $this->assertStringContainsString('href="'.route('m.ventes').'"', $html);
        $this->assertStringNotContainsString('href="'.route('m.commissions').'"', $html);
        $this->assertStringNotContainsString('href="'.route('m.dettes').'"', $html);
    }

    public function test_ventes_page_uses_the_same_widget_as_remboursements(): void
    {
        Livewire::actingAs($this->master)->test(Ventes\Index::class)
            ->assertSee('m-hero__label', false)->assertSee('Hier')->assertSee('Évolution')->assertSee('Moy.');
    }

    public function test_the_menu_tab_is_dettes_and_caisse_moved_to_the_more_menu(): void
    {
        $this->assertContains('Dettes', array_column(Nav::tabs($this->master), 'label'));
        $this->assertNotContains('Caisse', array_column(Nav::tabs($this->master), 'label'));

        $more = array_column(Nav::moreItems($this->master), 'label');
        $this->assertContains('Caisse', $more);
        $this->assertNotContains('Dettes', $more, 'Dettes est déjà dans la barre du bas');
    }

    public function test_new_sale_suggests_the_large_amounts(): void
    {
        $html = $this->actingAs($this->master)->get(route('m.ventes.create'))->assertOk()->getContent();

        preg_match_all("/class=\"m-chip\"[^>]*@click=\"raw = '(\d+)'\">([^<]+)</", $html, $chips, PREG_SET_ORDER);

        $this->assertSame(
            [['500 000', '500000'], ['1 000 000', '1000000'], ['2 000 000', '2000000'], ['5 000 000', '5000000']],
            array_map(fn (array $c): array => [$c[2], $c[1]], $chips),
        );
    }
}
