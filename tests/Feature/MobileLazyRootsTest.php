<?php

namespace Tests\Feature;

use App\Livewire\Mobile\HomeActivity;
use App\Livewire\Mobile\HomeAlerts;
use App\Livewire\Mobile\HomeChart;
use App\Livewire\Mobile\HomeMonth;
use App\Livewire\Mobile\HomeTopAgents;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Un composant « lazy » remplace son squelette (un <div>) par son vrai contenu : si la racine est une autre balise
 * (<section>…), Livewire lève « Could not find Livewire component in DOM tree » dans la WebView.
 */
class MobileLazyRootsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{class-string}> */
    public static function lazyBlocks(): array
    {
        return [
            'graphique' => [HomeChart::class],
            'mois' => [HomeMonth::class],
            'alertes' => [HomeAlerts::class],
            'activité' => [HomeActivity::class],
            'meilleurs agents' => [HomeTopAgents::class],
        ];
    }

    #[DataProvider('lazyBlocks')]
    public function test_each_deferred_home_block_keeps_a_div_root_like_its_placeholder(string $component): void
    {
        Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('master');

        $html = Livewire::actingAs($user)->withoutLazyLoading()->test($component)->html();

        $this->assertStringStartsWith('<div', ltrim($html));
    }
}
