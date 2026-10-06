<?php

namespace App\Services\Subscriptions;

/** Fonctionnalités connues (libellés pour l'écran d'administration). La liste d'une offre reste libre. */
class FeatureCatalog
{
    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'statistiques_essentielles' => 'Statistiques essentielles',
            'exports_pdf' => 'Exports PDF',
            'exports_excel' => 'Exports Excel',
            'notifications_dettes' => 'Notifications de dettes',
            'statistiques_avancees' => 'Statistiques avancées',
            'scoring_agents' => 'Scoring des agents',
            'import_donnees' => 'Import de données',
            'exports_avances' => 'Exports avancés',
            'notifications_push' => 'Notifications push',
            'rapports_avances' => 'Rapports avancés',
            'sauvegardes_dediees' => 'Sauvegardes dédiées',
            'assistance_prioritaire' => 'Assistance prioritaire',
            'options_personnalisees' => 'Options personnalisées',
            'toutes_les_fonctions_essentiel' => 'Toutes les fonctions Essentiel',
            'toutes_les_fonctions_pro' => 'Toutes les fonctions Pro',
        ];
    }

    /**
     * Fonctionnalités SERVICE : promesses commerciales sans équivalent technique à verrouiller dans l'application
     * (assistance humaine, développements sur mesure). Elles s'affichent et se testent via hasFeature(), mais aucun
     * écran n'est bloqué. Toutes les autres sont appliquées côté serveur (voir docs/SUBSCRIPTIONS.md, tableau d'application).
     *
     * @return list<string>
     */
    public static function serviceFeatures(): array
    {
        return ['assistance_prioritaire', 'options_personnalisees'];
    }

    /** @return list<string> fonctionnalités techniques appliquées côté serveur */
    public static function enforcedFeatures(): array
    {
        return array_values(array_filter(
            array_keys(self::labels()),
            fn (string $f) => ! in_array($f, self::serviceFeatures(), true) && ! str_starts_with($f, 'toutes_les_fonctions_')
        ));
    }

    public static function label(string $feature): string
    {
        return self::labels()[$feature] ?? ucfirst(str_replace('_', ' ', $feature));
    }
}
