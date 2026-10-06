<?php

return [
    /*
    | Nombre maximal de lignes listées dans un PDF d'export (ventes, remboursements, approvisionnements).
    | DomPDF consomme ~0,3 Mo par ligne : sur un hébergement mutualisé à 128 Mo, un PDF de plusieurs
    | centaines de lignes provoque une erreur 500 (mémoire épuisée). Laisser vide pour un plafond calculé
    | automatiquement d'après memory_limit ; l'export Excel n'est jamais limité.
    */
    'pdf_max_rows' => env('MOBILE_PDF_MAX_ROWS') ? (int) env('MOBILE_PDF_MAX_ROWS') : null,
];
