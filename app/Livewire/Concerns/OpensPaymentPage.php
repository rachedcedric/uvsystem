<?php

namespace App\Livewire\Concerns;

/**
 * Ouvre une page de paiement externe (Jèko) sans faire quitter l'application Android.
 *
 * Une redirection serveur (`$this->redirect()`) ferait naviguer la WebView vers un autre site : Android l'ouvrirait alors
 * dans le navigateur système, hors de l'appli. Le pont natif expose `window.uvmasterOpenExternal`, qui ouvre la page dans un
 * onglet intégré à l'appli (plugin Browser de Capacitor). Dans un navigateur classique le pont n'existe pas : repli sur une
 * navigation normale.
 */
trait OpensPaymentPage
{
    /** @param  string  $url  adresse déjà validée par la passerelle (https et hôte autorisé) */
    protected function openPaymentPage(string $url): void
    {
        // JSON_HEX_* : l'adresse ne peut jamais sortir de la chaîne JavaScript, quel que soit son contenu.
        $literal = json_encode($url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);

        $this->js("(window.uvmasterOpenExternal || function (u) { window.location.assign(u); })({$literal})");
    }
}
