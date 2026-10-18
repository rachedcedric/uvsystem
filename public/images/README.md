# Images de marque UVMASTER

- `logo-wide.png` : logo principal (fond blanc, texte sombre) : connexion, pages publiques, e-mails, PDF.
- `logo-wide-dark.png` : variante à texte clair pour le mode sombre.
- `logo.png` et `logo-dark.png` : copies conservées pour compatibilité (même visuel que les deux ci-dessus).
- `../favicon-32.png`, `../favicon.ico`, `../apple-touch-icon.png` : icônes d'onglet et d'écran d'accueil, générées depuis
  `resources/branding/app-icon.png` par `scripts/generate-app-icons.py`.

Le serveur web sert ces images avec `Cache-Control: immutable` (30 jours). Ne les référencez jamais directement : passez par
`App\Support\BrandAsset::url()` (ajoute `?v=` + date du fichier), sinon un navigateur garde l'ancienne image.
