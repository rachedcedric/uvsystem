<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Les pages publiques (offres, souscription, confirmation) étaient conçues pour un téléphone : sur ordinateur elles
 * s'affichaient en colonne étroite. Ce test garde la mise en page de bureau (au-dessus de 900 px) et celle du téléphone.
 */
class PublicPagesDesktopLayoutTest extends TestCase
{
    public function test_the_public_layout_has_a_desktop_presentation_and_keeps_the_phone_one(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/public.blade.php'));

        $this->assertStringContainsString('@media (min-width:900px)', $layout);
        $this->assertStringContainsString('.card form.cols{display:grid;grid-template-columns:1fr 1fr', $layout);
        $this->assertStringContainsString('name="viewport" content="width=device-width, initial-scale=1.0"', $layout);
        // Téléphone : une seule colonne par défaut (la grille à deux colonnes n'existe qu'au-dessus de 900 px).
        $this->assertStringNotContainsString('}form.cols{display:grid', $layout);
    }

    public function test_the_signup_form_fields_are_laid_out_in_pairs_on_desktop(): void
    {
        $form = (string) file_get_contents(resource_path('views/public/inscription.blade.php'));

        $this->assertStringContainsString('class="cols"', $form);
        $this->assertSame(6, substr_count($form, '<div class="field">'));
        $this->assertStringContainsString('class="check wide"', $form);
    }
}
