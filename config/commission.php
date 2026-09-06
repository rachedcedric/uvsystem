<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Commission Business Day Configuration
    |--------------------------------------------------------------------------
    |
    | Heure de clôture de la journée métier de commission. Toute vente
    | enregistrée à partir de cette heure (incluse) appartient à la journée
    | métier suivante. Doit toujours être interprétée dans le fuseau horaire
    | de l'application (config('app.timezone')).
    |
    */

    'cutoff_time' => env('COMMISSION_CUTOFF_TIME', '18:02:00'),

    /*
    |--------------------------------------------------------------------------
    | Fenêtre de rattrapage
    |--------------------------------------------------------------------------
    |
    | Nombre de jours en arrière balayés par le rattrapage automatique
    | (commissions:rattraper) et par la vérification/correction pour
    | détecter des journées manquantes ou incorrectes.
    |
    */

    'catchup_window_days' => (int) env('COMMISSION_CATCHUP_WINDOW_DAYS', 30),
];
