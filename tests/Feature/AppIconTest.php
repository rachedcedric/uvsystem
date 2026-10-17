<?php

namespace Tests\Feature;

use App\Support\BrandAsset;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Icône de l'application (« UV MASTER ») : fichiers générés par scripts/generate-app-icons.py, définitions Android, favicons
 * versionnés. Le splash et l'écran de chargement gardent le glyphe « UV » validé : l'icône du lanceur ne doit pas les changer.
 */
class AppIconTest extends TestCase
{
    use RefreshDatabase; // la page des offres lit les offres en base

    private const RES = 'android/app/src/main/res/';

    private function image(string $path): \GdImage
    {
        $image = imagecreatefrompng(base_path($path));
        $this->assertNotFalse($image, $path);

        return $image;
    }

    private function alphaAt(\GdImage $image, int $x, int $y): int
    {
        return (imagecolorat($image, $x, $y) >> 24) & 0x7F; // 0 = opaque, 127 = transparent (GD)
    }

    public function test_the_source_and_the_generator_are_in_the_project(): void
    {
        $this->assertFileExists(base_path('resources/branding/app-icon.png'));
        $this->assertFileExists(base_path('scripts/generate-app-icons.py'));
        $this->assertSame([1254, 1254], array_slice(getimagesize(base_path('resources/branding/app-icon.png')), 0, 2));
    }

    public function test_every_android_density_has_its_launcher_icons_at_the_right_size(): void
    {
        // densité => [côté de l'icône classique, côté de la grille adaptative 108 dp]
        foreach (['mdpi' => [48, 108], 'hdpi' => [72, 162], 'xhdpi' => [96, 216], 'xxhdpi' => [144, 324], 'xxxhdpi' => [192, 432]] as $density => [$legacy, $adaptive]) {
            $folder = self::RES."mipmap-{$density}/";

            $this->assertSame([$legacy, $legacy], array_slice(getimagesize(base_path($folder.'ic_launcher.png')), 0, 2), "{$density} ic_launcher");
            $this->assertSame([$legacy, $legacy], array_slice(getimagesize(base_path($folder.'ic_launcher_round.png')), 0, 2), "{$density} ic_launcher_round");
            $this->assertSame([$adaptive, $adaptive], array_slice(getimagesize(base_path($folder.'ic_launcher_brand_foreground.png')), 0, 2), "{$density} premier plan adaptatif");

            // Icône classique : coins arrondis transparents (un carré blanc plein serait un défaut sur les anciens Android).
            $this->assertGreaterThan(100, $this->alphaAt($this->image($folder.'ic_launcher.png'), 0, 0), "{$density} coin transparent");
            // Icône ronde : aussi transparente dans les coins.
            $this->assertGreaterThan(100, $this->alphaAt($this->image($folder.'ic_launcher_round.png'), 0, 0), "{$density} rond transparent");
        }
    }

    public function test_the_adaptive_foreground_stays_inside_the_launcher_safe_zone(): void
    {
        $image = $this->image(self::RES.'mipmap-xxxhdpi/ic_launcher_brand_foreground.png');
        $size = imagesx($image); // 432 px = grille de 108 dp
        $radius = $size * 33 / 108; // le lanceur ne garantit que le disque central de 66 dp
        $center = $size / 2;
        $ink = 0;

        for ($y = 0; $y < $size; $y += 2) {
            for ($x = 0; $x < $size; $x += 2) {
                if ($this->alphaAt($image, $x, $y) < 100) { // pixel visible
                    $ink++;
                    $this->assertLessThanOrEqual($radius + 2, hypot($x - $center, $y - $center), "pixel visible hors de la zone sûre en ({$x}, {$y}) : le lanceur le rognerait");
                }
            }
        }

        $this->assertGreaterThan(3000, $ink, 'le premier plan contient bien « UV MASTER » (et pas une image vide)');
        $this->assertGreaterThan(100, $this->alphaAt($image, 0, 0), 'fond transparent : le blanc vient de la couleur de fond adaptative');
    }

    public function test_the_adaptive_icon_uses_the_new_foreground_on_a_white_background(): void
    {
        foreach (['ic_launcher', 'ic_launcher_round'] as $name) {
            $xml = file_get_contents(base_path(self::RES."mipmap-anydpi-v26/{$name}.xml"));

            $this->assertStringContainsString('@mipmap/ic_launcher_brand_foreground', $xml, $name);
            $this->assertStringContainsString('@color/ic_launcher_background', $xml, $name);
            $this->assertStringNotContainsString('@mipmap/ic_launcher_foreground', $xml, "{$name} ne doit plus pointer vers le glyphe du splash");
        }

        $this->assertStringContainsString('#FFFFFF', file_get_contents(base_path(self::RES.'values/ic_launcher_background.xml')));
        $this->assertSame([512, 512], array_slice(getimagesize(base_path('android/app/src/main/ic_launcher-playstore.png')), 0, 2));
    }

    public function test_the_splash_keeps_the_uv_glyph_and_does_not_follow_the_launcher_icon(): void
    {
        $styles = file_get_contents(base_path(self::RES.'values/styles.xml'));

        // Sans cette ligne, le splash système reprendrait l'icône du lanceur (devenue « UV MASTER »).
        $this->assertMatchesRegularExpression('/AppTheme\.NoActionBarLaunch.*?windowSplashScreenAnimatedIcon">@mipmap\/ic_launcher_foreground</s', $styles);

        // L'écran de chargement natif utilise le même glyphe : le splash et lui doivent rester superposés.
        $this->assertStringContainsString('@mipmap/ic_launcher_foreground', file_get_contents(base_path(self::RES.'layout/loading_overlay.xml')));
        $this->assertSame([432, 432], array_slice(getimagesize(base_path(self::RES.'mipmap-xxxhdpi/ic_launcher_foreground.png')), 0, 2));
    }

    public function test_the_web_icons_exist_and_the_favicon_contains_three_sizes(): void
    {
        $this->assertSame([32, 32], array_slice(getimagesize(public_path('favicon-32.png')), 0, 2));
        $this->assertSame([180, 180], array_slice(getimagesize(public_path('apple-touch-icon.png')), 0, 2));

        // En-tête ICO : 3 images de 16, 32 et 48 px.
        $ico = file_get_contents(public_path('favicon.ico'));
        $header = unpack('vreserved/vtype/vcount', $ico);
        $this->assertSame([0, 1, 3], [$header['reserved'], $header['type'], $header['count']]);

        $sizes = [];
        for ($i = 0; $i < 3; $i++) {
            $sizes[] = ord($ico[6 + $i * 16]);
        }
        sort($sizes);
        $this->assertSame([16, 32, 48], $sizes);
    }

    public function test_web_pages_and_panels_reference_versioned_icons(): void
    {
        $versioned = BrandAsset::url('favicon-32.png');
        $this->assertMatchesRegularExpression('/\/favicon-32\.png\?v=\d+$/', $versioned);
        $this->assertSame('?v='.filemtime(public_path('favicon-32.png')), substr($versioned, strpos($versioned, '?')));

        // Pages web : favicon, icône classique et icône d'écran d'accueil, tous versionnés (le serveur les met 30 jours en cache).
        $html = $this->get(route('offres'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<link rel="icon"[^>]*favicon-32\.png\?v=\d+/', $html);
        $this->assertMatchesRegularExpression('/<link rel="icon" href="[^"]*favicon\.ico\?v=\d+/', $html);
        $this->assertMatchesRegularExpression('/<link rel="apple-touch-icon" href="[^"]*apple-touch-icon\.png\?v=\d+/', $html);

        foreach (['platform', 'admin', 'agent'] as $panel) {
            $this->assertMatchesRegularExpression('/favicon-32\.png\?v=\d+$/', (string) Filament::getPanel($panel)->getFavicon(), $panel);
        }
    }
}
