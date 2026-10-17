<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    public function test_a_missing_page_shows_a_french_page_with_a_way_back(): void
    {
        $response = $this->get('/m/cette-page-n-existe-pas');

        $response->assertNotFound()
            ->assertSee('Page introuvable')
            ->assertSee('Retour')
            ->assertSee('Accueil');
    }

    public function test_a_forbidden_page_explains_the_refusal_and_links_back_to_the_mobile_home(): void
    {
        Route::get('/m/__refus', fn () => abort(403));

        $this->get('/m/__refus')
            ->assertForbidden()
            ->assertSee('Accès refusé')
            ->assertSee(url('/m'), false);
    }

    public function test_every_error_code_the_apk_can_meet_has_a_branded_view(): void
    {
        foreach ([403, 404, 419, 429, 500, 503] as $code) {
            $html = view('errors.'.$code)->render();

            $this->assertStringContainsString('<html lang="fr">', $html, "errors.{$code}");
            $this->assertStringContainsString((string) $code, $html, "errors.{$code}");
        }
    }

    public function test_the_android_shell_keeps_the_offline_page_for_real_network_failures_only(): void
    {
        $java = (string) file_get_contents(base_path('android/app/src/main/java/ci/uvmaster/app/MainActivity.java'));

        // Capacitor charge « Pas de connexion » sur toute réponse HTTP 4xx/5xx : le serveur répond pourtant, on affiche donc sa page.
        $this->assertStringContainsString('onReceivedHttpError', $java);
        $this->assertStringNotContainsString('super.onReceivedHttpError', $java);
        $this->assertStringNotContainsString('public void onReceivedError(', $java);
    }

    public function test_the_android_shell_caps_the_system_font_scale_so_the_layout_never_breaks(): void
    {
        $java = (string) file_get_contents(base_path('android/app/src/main/java/ci/uvmaster/app/MainActivity.java'));

        // Constaté à 200 % : solde rogné, libellés d'onglets coupés. On plafonne l'agrandissement à 150 %.
        $this->assertStringContainsString('MAX_TEXT_ZOOM = 150', $java);
        $this->assertStringContainsString('setTextZoom(', $java);
    }

    public function test_the_android_shell_refreshes_the_status_bar_contrast_when_the_theme_changes(): void
    {
        $java = (string) file_get_contents(base_path('android/app/src/main/java/ci/uvmaster/app/MainActivity.java'));

        $this->assertStringContainsString('onConfigurationChanged', $java);
        $this->assertStringContainsString('setAppearanceLightStatusBars', $java);
    }

    public function test_the_bridge_closes_the_filament_sidebar_on_phones(): void
    {
        $js = (string) file_get_contents(public_path('js/capacitor-bridge.js'));

        $this->assertStringContainsString('closeSidebarOnPhones', $js);
        $this->assertStringContainsString("store('sidebar')", $js);
    }
}
