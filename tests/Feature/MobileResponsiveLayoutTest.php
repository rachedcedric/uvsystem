<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * L'interface mobile s'adapte à la largeur réelle de la fenêtre (tablette, pliable, écran partagé, téléphone en paysage) au
 * lieu de rester une colonne de téléphone centrée dans une marge vide. Ces garde-fous lisent la feuille de style : le rendu a
 * été validé à la main sur 390, 600, 820, 1280 et 1920 px (aucun débordement horizontal sur les écrans /m).
 */
class MobileResponsiveLayoutTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/mobile.css');
    }

    public function test_the_app_is_no_longer_locked_in_a_fixed_phone_column_on_wide_screens(): void
    {
        $css = $this->css();

        $this->assertStringNotContainsString('--m-col', $css, 'plus de colonne centrée de 560 px');
        $this->assertDoesNotMatchRegularExpression('/\.m-app\s*\{[^}]*max-width/s', $css, 'le corps de l\'app ne doit pas être borné');
        $this->assertMatchesRegularExpression('/@media \(min-width: 600px\)\s*\{[^@]*--m-content-max/s', $css);
    }

    public function test_the_kpi_strip_wraps_into_a_grid_instead_of_cutting_cards_off(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 600px\)\s*\{.*?\.m-strip\s*\{[^}]*display:\s*grid[^}]*overflow:\s*visible/s',
            $this->css(),
        );
    }

    public function test_wide_screens_and_landscape_phones_get_a_navigation_rail(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('@media (min-width: 900px), (min-width: 600px) and (orientation: landscape) and (max-height: 520px)', $css);
        $this->assertMatchesRegularExpression('/--rail-w:\s*calc\(88px \+ var\(--sal\)\)/', $css, 'la zone sûre (encoche) est comprise dans le rail');
        $this->assertMatchesRegularExpression('/body\.m-app\s*\{\s*padding-left:\s*var\(--rail-w\)/', $css);
    }

    public function test_sheets_toasts_and_forms_stay_readable_on_a_large_screen(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression('/\.m-sheet\s*\{[^}]*width:\s*min\(100%,\s*var\(--m-dialog-max\)\)/s', $css);
        $this->assertMatchesRegularExpression('/\.m-form\s*\{[^}]*max-width:\s*720px/s', $css);
        $this->assertMatchesRegularExpression('/\.m-login\s*\{[^}]*max-width:\s*480px/s', $css);
    }

    public function test_every_adaptive_rule_lives_inside_a_media_query_so_phones_in_portrait_are_untouched(): void
    {
        $css = $this->css();
        $start = (int) strpos($css, 'adaptatif : tablette et grands écrans');
        $end = (int) strpos($css, '/* ---', $start + 10);
        $section = substr($css, $start, $end > $start ? $end - $start : null);

        $outsideMedia = (string) preg_replace('/@media[^{]+\{(?:[^{}]|\{[^{}]*\})*\}/s', '', $section);

        $this->assertDoesNotMatchRegularExpression('/\.m-(main|strip|grid|tabbar|sheet|toast|fab)\s*\{/', $outsideMedia);
    }
}
