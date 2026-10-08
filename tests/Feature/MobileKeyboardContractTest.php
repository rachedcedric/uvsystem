<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Contrat du clavier mobile (Android, WebView Capacitor). Le comportement réel (touche d'action, défilement au-dessus
 * du clavier) se vérifie sur appareil ; ces tests empêchent seulement de perdre les garanties posées dans le code.
 */
class MobileKeyboardContractTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    public function test_android_resizes_the_window_when_the_keyboard_opens(): void
    {
        $this->assertStringContainsString('android:windowSoftInputMode="adjustResize"', $this->source('android/app/src/main/AndroidManifest.xml'));
    }

    public function test_the_mobile_script_sets_the_action_key_and_keeps_the_active_field_visible(): void
    {
        $js = $this->source('resources/js/mobile.js');

        foreach (['enterkeyhint', "'next'", "'done'", "'search'", "'enter'", 'visualViewport', 'scrollIntoView', 'focusin', 'MutationObserver'] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        $this->assertStringContainsString('adjustResize', $this->source('android/app/src/main/AndroidManifest.xml'));
        $this->assertStringContainsString('scroll-margin-block', $this->source('resources/css/mobile.css'));
    }

    public function test_field_types_open_the_right_keyboard(): void
    {
        $login = $this->source('resources/views/livewire/mobile/login.blade.php');
        $this->assertMatchesRegularExpression('/type="email"[^>]*wire:model="email"|wire:model="email"[^>]*type="email"/s', $login);
        $this->assertStringContainsString("'password'", $login);

        $money = $this->source('resources/views/components/m/money.blade.php');
        $this->assertStringContainsString('inputmode="numeric"', $money);

        $search = $this->source('resources/views/components/m/search.blade.php');
        $this->assertStringContainsString('type="search"', $search);

        $otp = $this->source('resources/views/livewire/mobile/admin/two-factor.blade.php');
        $this->assertStringContainsString('inputmode="numeric"', $otp);
        $this->assertStringContainsString('autocomplete="one-time-code"', $otp);

        $form = $this->source('resources/views/livewire/mobile/crud/form.blade.php');
        $this->assertStringContainsString("in_array(\$field->type, ['email', 'tel'])", $form);
        $this->assertStringContainsString('inputmode="decimal"', $form);
    }
}
