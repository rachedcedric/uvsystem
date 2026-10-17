<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Contrats du démarrage de l'APK : fond de la WebView, splash système, écran de chargement natif, page « Pas de
 * connexion », pont natif. Ils lisent les sources (le code Android ne s'exécute pas sous PHPUnit) ; la logique
 * Java pure est couverte par les tests JVM (LoadingStateTest, TrustedOriginTest).
 */
class AndroidSplashContractTest extends TestCase
{
    private const JAVA = 'android/app/src/main/java/ci/uvmaster/app/';

    private function source(string $path): string
    {
        $content = file_get_contents(base_path($path));

        $this->assertNotFalse($content, $path);

        return $content;
    }

    public function test_the_webview_background_comes_from_the_theme_colors_and_follows_theme_changes(): void
    {
        $main = $this->source(self::JAVA.'MainActivity.java');

        $this->assertStringContainsString('setBackgroundColor(ContextCompat.getColor(this, R.color.m_bg))', $main);

        // Appliqué au démarrage ET à chaque changement de thème (l'activité déclare uiMode dans configChanges).
        $this->assertSame(3, substr_count($main, 'applyWebViewBackground()'), 'définition + onCreate + onConfigurationChanged');
        $this->assertMatchesRegularExpression('/onConfigurationChanged\(Configuration newConfig\)\s*\{[^}]*applyWebViewBackground\(\)/s', $main);

        $this->assertStringContainsString('name="m_bg"', $this->source('android/app/src/main/res/values/colors.xml'));
        $this->assertStringContainsString('name="m_bg"', $this->source('android/app/src/main/res/values-night/colors.xml'));
    }

    public function test_the_system_splash_hands_over_immediately_to_the_native_loading_screen(): void
    {
        $config = json_decode($this->source('capacitor.config.json'), true, flags: JSON_THROW_ON_ERROR);

        // Une durée > 0 bloque le premier dessin : l'écran de chargement natif ne serait jamais visible.
        $this->assertSame(0, $config['plugins']['SplashScreen']['launchShowDuration']);
        $this->assertSame('https://uvmaster.ci/m', $config['server']['url'], 'le dépôt garde l\'URL de production');
        $this->assertFalse($config['server']['cleartext']);
    }

    public function test_no_script_hides_the_splash_through_the_capacitor_plugin_any_more(): void
    {
        foreach (['resources/js/mobile.js', 'public/js/capacitor-bridge.js', 'capacitor-www/index.html'] as $file) {
            $this->assertStringNotContainsString('SplashScreen', $this->source($file), $file);
            $this->assertStringContainsString('appReady', $this->source($file), $file);
        }
    }

    public function test_the_web_splash_delays_are_short(): void
    {
        $js = $this->source('resources/js/mobile.js');

        $this->assertStringContainsString('var SPLASH_MIN_MS = 300;', $js);
        $this->assertStringContainsString('var FONT_WAIT_MS = 1000;', $js);
        $this->assertStringNotContainsString('1800', $js);
    }

    public function test_the_offline_page_has_no_hard_coded_server_and_retries_through_the_native_bridge(): void
    {
        $page = $this->source('capacitor-www/index.html');

        $this->assertDoesNotMatchRegularExpression('#https?://#i', $page, 'aucune URL écrite en dur (production ou autre)');
        $this->assertStringNotContainsString('uvmaster.ci', $page);
        $this->assertStringContainsString('native().retry()', $page);
        $this->assertStringContainsString('Réessayer', $page);
        $this->assertStringContainsString('is-loading', $page);

        // Une seule relance automatique par panne, jamais en boucle.
        $this->assertStringContainsString("'uvmaster-auto-retry'", $page);
        $this->assertStringContainsString("addEventListener('online'", $page);
        $this->assertStringContainsString("addEventListener('offline'", $page);
    }

    public function test_the_offline_page_carries_the_brand_in_both_themes(): void
    {
        $page = $this->source('capacitor-www/index.html');

        // Avec un serveur distant, Capacitor ne sert localement que la page d'erreur elle-même : un logo en fichier
        // voisin partirait au proxy HTTP et s'afficherait cassé. Les logos sont donc intégrés (data:), et ils doivent
        // rester identiques à ceux du site.
        $this->assertSame(1, preg_match('#class="logo logo-light" src="data:image/png;base64,([A-Za-z0-9+/=]+)"#', $page, $light));
        $this->assertSame(1, preg_match('#class="logo logo-dark" src="data:image/png;base64,([A-Za-z0-9+/=]+)"#', $page, $dark));
        $this->assertSame(file_get_contents(public_path('images/logo-wide.png')), base64_decode($light[1], true));
        $this->assertSame(file_get_contents(public_path('images/logo-wide-dark.png')), base64_decode($dark[1], true));

        $this->assertStringContainsString('.logo-light { display: none; }', $page);
        $this->assertStringContainsString('prefers-color-scheme: dark', $page);
        $this->assertDoesNotMatchRegularExpression('#(src|srcset)="(?!data:)[^"]+\.(png|jpe?g|svg|webp)"#i', $page, 'aucune image en fichier voisin');
        $this->assertSame(['index.html'], array_values(array_diff(scandir(base_path('capacitor-www')), ['.', '..', '.DS_Store'])));
    }

    public function test_the_dark_logo_is_a_new_transparent_variant_and_the_original_is_untouched(): void
    {
        $dark = public_path('images/logo-wide-dark.png');

        $this->assertFileExists($dark);
        $this->assertSame([393, 92], array_slice(getimagesize($dark), 0, 2));

        // Octet 25 de l'en-tête PNG = type de couleur : 6 = RGBA (transparence), 2 = RGB (original, fond blanc).
        $this->assertSame(6, ord(file_get_contents($dark)[25]), 'le logo sombre doit être RGBA');
        $this->assertSame(2, ord(file_get_contents(public_path('images/logo-wide.png'))[25]), 'logo clair d\'origine : RGB inchangé');
    }

    public function test_the_web_splash_swaps_the_logo_in_dark_mode_and_drops_the_white_plate(): void
    {
        $layout = $this->source('resources/views/components/layouts/mobile.blade.php');

        $this->assertStringContainsString('<source media="(prefers-color-scheme: dark)" srcset="{{ asset(\'images/logo-wide-dark.png\') }}">', $layout);
        $this->assertMatchesRegularExpression(
            '/\.m-logo\.m-splash__logo\s*\{[^}]*padding:\s*0;[^}]*background:\s*none;/s',
            $this->source('resources/css/mobile.css'),
        );
    }

    public function test_the_native_bridge_exposes_ready_and_retry_only_to_trusted_origins(): void
    {
        $native = $this->source(self::JAVA.'UvmasterNative.java');

        // hasFirebase + appReady + retry : toute nouvelle méthode exposée à la page doit être pensée (et testée).
        $this->assertSame(3, preg_match_all('/^\s*@JavascriptInterface\s*$/m', $native));
        $this->assertMatchesRegularExpression('/@JavascriptInterface\s+public void appReady\(\)\s*\{.*?isTrustedUrl\(host\.currentUrl\(\)\).*?onAppReady\(\)/s', $native);
        // retry() est plus strict qu'appReady() : voir test_retry_is_limited_to_the_local_offline_page.
        $this->assertMatchesRegularExpression('/@JavascriptInterface\s+public void retry\(\)\s*\{.*?isErrorPageUrl\(host\.currentUrl\(\)\).*?onRetry\(\)/s', $native);

        $main = $this->source(self::JAVA.'MainActivity.java');

        // Relance de l'URL réellement configurée, pas d'une adresse écrite dans le code.
        $this->assertMatchesRegularExpression('/public void onRetry\(\)\s*\{[^}]*getBridge\(\)\.reload\(\)/s', $main);
        $this->assertStringContainsString('TrustedOrigin.isTrusted(url, getBridge().getServerUrl()', $main);

        foreach (['MainActivity', 'UvmasterNative', 'LoadingOverlay', 'LoadingState', 'TrustedOrigin'] as $class) {
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $this->source(self::JAVA.$class.'.java'));

            $this->assertDoesNotMatchRegularExpression('#https?://[a-z0-9]#i', $code, "$class : aucune URL en dur");
        }
    }

    public function test_retry_is_limited_to_the_local_offline_page(): void
    {
        $native = $this->source(self::JAVA.'UvmasterNative.java');
        $main = $this->source(self::JAVA.'MainActivity.java');

        // Corps de retry() : seule la page d'erreur locale est acceptée, jamais « n'importe quelle origine de confiance »
        // (une iframe d'une page du serveur verrait l'URL de la fenêtre principale, fiable).
        $this->assertSame(1, preg_match('/public void retry\(\)\s*\{(.*?)\n    \}/s', $native, $retry));
        $this->assertStringContainsString('host.isErrorPageUrl(host.currentUrl())', $retry[1]);
        $this->assertStringNotContainsString('isTrustedUrl', $retry[1]);

        // appReady() garde son contrôle d'origine : ce correctif ne l'élargit ni ne le retire.
        $this->assertSame(1, preg_match('/public void appReady\(\)\s*\{(.*?)\n    \}/s', $native, $ready));
        $this->assertStringContainsString('host.isTrustedUrl(host.currentUrl())', $ready[1]);

        // L'URL autorisée vient de Capacitor (getErrorUrl), pas d'une constante ; la comparaison est stricte
        // (origine TrustedOrigin + chemin brut identique).
        $this->assertMatchesRegularExpression('/public boolean isErrorPageUrl\(String url\)\s*\{\s*return UvmasterNative\.isSamePage\(url, getBridge\(\)\.getErrorUrl\(\)\);/s', $main);
        $this->assertStringContainsString('boolean isErrorPageUrl(String url);', $native);
        $this->assertMatchesRegularExpression('/static boolean isSamePage\(String url, String pageUrl\)\s*\{\s*if \(!TrustedOrigin\.isTrusted\(url, pageUrl\)\)/s', $native);
        $this->assertStringContainsString('getRawPath()', $native);
        $this->assertStringNotContainsString('getPath()', $native, 'chemin brut uniquement : pas de décodage');
        $this->assertStringNotContainsString('startsWith', $native);

        // La page hors connexion continue d'appeler le pont, depuis l'origine locale qui, elle, est acceptée.
        $page = $this->source('capacitor-www/index.html');
        $this->assertStringContainsString('native().retry()', $page);
        $this->assertStringContainsString('errorPath', $this->source('capacitor.config.json'));
    }

    public function test_the_loading_overlay_keeps_the_agreed_thresholds_and_texts(): void
    {
        $this->assertStringContainsString('SLOW_AFTER_MS = 4_000', $this->source(self::JAVA.'LoadingState.java'));
        $this->assertStringContainsString('FAILED_AFTER_MS = 12_000', $this->source(self::JAVA.'LoadingState.java'));

        $strings = $this->source('android/app/src/main/res/values/strings.xml');

        foreach (['loading_slow', 'loading_failed_title', 'loading_failed_text', 'loading_retry'] as $name) {
            $this->assertStringContainsString('name="'.$name.'"', $strings);
        }

        $this->assertStringContainsString('Connexion lente…', $strings);
    }

    public function test_the_loading_screen_uses_the_signature_design_and_never_moves_the_glyph(): void
    {
        $layout = $this->source('android/app/src/main/res/layout/loading_overlay.xml');
        $overlay = $this->source(self::JAVA.'LoadingOverlay.java');
        $strings = $this->source('android/app/src/main/res/values/strings.xml');

        // Direction « Signature » : nom, baseline, trois points et léger dégradé ; plus de barre de progression.
        $this->assertStringContainsString('android:id="@+id/uvmaster_loading_wordmark"', $layout);
        $this->assertMatchesRegularExpression('/uvmaster_loading_wordmark".*?android:text="@string\/app_name"/s', $layout);
        $this->assertMatchesRegularExpression('/uvmaster_loading_tagline".*?android:text="@string\/loading_tagline"/s', $layout);
        $this->assertSame(3, substr_count($layout, '@drawable/loading_dot'), 'trois points exactement');
        $this->assertStringContainsString('@drawable/loading_gradient', $layout);
        $this->assertStringNotContainsString('ProgressBar', $layout);
        $this->assertStringContainsString('La gestion des UV simplifiée', $strings);
        $this->assertStringContainsString('name="loading_tagline"', $strings);

        // Le glyphe est celui du splash système : même taille, centré, jamais décalé ni animé (sinon saut au passage).
        $this->assertStringContainsString('<dimen name="loading_glyph_size">288dp</dimen>', $this->source('android/app/src/main/res/values/dimens.xml'));
        $this->assertSame(1, preg_match('/<ImageView\s[^>]*uvmaster_loading_glyph[^>]*>/s', $layout, $glyph));
        $this->assertStringNotContainsString('translation', $glyph[0]);
        $this->assertStringNotContainsString('alpha', $glyph[0]);
        $this->assertStringNotContainsString('uvmaster_loading_glyph', $overlay, 'le code ne touche jamais au glyphe');
        $this->assertStringContainsString('centerOnWindow', $overlay);

        // « Réduire les animations » d'Android : aucun mouvement quand l'échelle de durée vaut 0.
        $this->assertStringContainsString('Settings.Global.ANIMATOR_DURATION_SCALE', $overlay);
        $this->assertGreaterThanOrEqual(4, substr_count($overlay, 'animationsEnabled()'));

        // Couleurs du dégradé et des points, en clair ET en sombre (deux valeurs distinctes).
        $light = $this->source('android/app/src/main/res/values/colors.xml');
        $dark = $this->source('android/app/src/main/res/values-night/colors.xml');

        foreach (['m_accent', 'm_splash_top', 'm_splash_bottom'] as $name) {
            $this->assertSame(1, preg_match('/name="'.$name.'">(#[0-9A-Fa-f]{6})</', $light, $l), "$name clair");
            $this->assertSame(1, preg_match('/name="'.$name.'">(#[0-9A-Fa-f]{6})</', $dark, $d), "$name sombre");
            $this->assertNotSame(strtoupper($l[1]), strtoupper($d[1]), "$name doit différer entre clair et sombre");
        }

        // Le dégradé se superpose au fond uni du splash système sans le remplacer.
        $this->assertStringContainsString('android:background="@color/m_bg"', $layout);
        $this->assertMatchesRegularExpression('/uvmaster_loading_gradient".*?android:alpha="0"/s', $layout);
    }

    public function test_the_existing_error_page_contracts_of_the_activity_are_intact(): void
    {
        $main = $this->source(self::JAVA.'MainActivity.java');

        $this->assertStringContainsString('onReceivedHttpError', $main);
        $this->assertStringNotContainsString('super.onReceivedHttpError', $main);
        $this->assertStringNotContainsString('public void onReceivedError(', $main);
        $this->assertStringContainsString('MAX_TEXT_ZOOM = 150', $main);
        $this->assertStringContainsString('setAppearanceLightStatusBars', $main);
    }
}
