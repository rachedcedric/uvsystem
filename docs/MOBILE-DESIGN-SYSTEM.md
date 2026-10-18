# Design system mobile (Phase 3) — application `/m` et APK

Le design system **formalise l'existant** (`resources/css/mobile.css`, `resources/views/components/m/*`) et le complète
là où l'audit a trouvé des manques. Il ne remplace rien et ne touche à aucune règle métier : les écrans appellent les
mêmes services, les mêmes `Gate` et la même isolation par entreprise qu'avant.

> **Périmètre.** Écran pilote officiel : **Ventes UV** (liste, fiche, création). Les **24 écrans Phase 3 ne sont pas encore
> déterminés** (⚪) : voir [MOBILE-INVENTORY-86.md](MOBILE-INVENTORY-86.md). Rien n'est généralisé avant la validation du pilote
> sur appareil Android physique.

## 1. Principes

1. **Le serveur décide.** L'interface reflète les droits (`@can`, `Gate`) pour ne pas proposer d'action impossible, mais
   chaque action Livewire et chaque route re-vérifie (`Gate::authorize`, `ReadOnlyGuard`). Un bouton masqué n'est jamais une protection.
2. **L'entreprise vient de l'utilisateur connecté**, jamais d'un identifiant envoyé par le client ; les modèles portent le
   scope d'entreprise (un identifiant étranger donne 404).
3. **Le sens ne passe jamais par la couleur seule** (texte « Annulée », barré + « (annulé) » pour les lecteurs d'écran).
4. **Montants toujours complets** : « 2 800 000 FCFA », jamais « 2,8 M ». Chiffres tabulaires.
5. **Zone tactile ≥ 44 px** (puces, boutons, lignes) ; 52 px pour les actions sensibles.
6. **Pas de style en ligne pour de la mise en forme réutilisable** : on crée un composant ou une classe.

## 2. Jetons (`:root` de `mobile.css`, thème sombre via `prefers-color-scheme`)

| Famille | Jetons | Remarque |
|---|---|---|
| Surfaces | `--bg`, `--surface`, `--surface-2`, `--line` | Carte = `--surface` sur `--bg` |
| Texte | `--fg` (principal), `--fg-2` (secondaire), `--fg-3` (discret) | `--fg-3` ≥ 4,5:1 sur `--surface` et `--bg` |
| Marque | `--brand`, `--brand-fg`, `--brand-soft`, `--leaf` | `--brand-fg` = texte sur fond de marque ou d'alerte |
| États | `--ok`, `--warn`, `--danger` (+ `-soft` pour les pastilles) | texte ≥ 4,5:1 sur sa pastille |
| Rayons | `--r-xl 28`, `--r-lg 18`, `--r-md 12`, `--r-sm 8` | |
| Typo | `--font-display` (titres/chiffres), `--font-ui` | |
| Zones sûres | `--sat`, `--sab`, `--sal`, `--sar` | encoche, barre de gestes |
| Barre d'onglets | `--tab-h: 64px` | |

Contrastes (WCAG AA, texte normal 4,5:1) **vérifiés par test** (`MobileDesignSystemTest`) dans les deux thèmes.
L'audit du 2026-10-08 a corrigé en thème clair : `--fg-3` (3,81 → 4,86), `--ok`, `--warn`, `--danger` (3,06–4,23 → ≥ 4,5).

## 3. Composants (`resources/views/components/m/`)

| Composant | Rôle | Statut |
|---|---|---|
| `x-m.appbar` | Titre de page, retour, actions | existant |
| `x-m.row` | Ligne de liste (icône/initiales, titre, sous-titre, fin, chevron ; `external` = navigation complète) | existant |
| `x-m.section`, `x-m.day` | Titre de section ; en-tête de jour avec total | existant |
| `x-m.search`, `x-m.chips` | Recherche ; puces de choix (44 px) | existant |
| `x-m.period-stats`, `x-m.period-dashboard` | Cartes de période | existant |
| `x-m.field`, `x-m.money`, `x-m.agent-picker`, `x-m.option-picker`, `x-m.operation-date-time` | Champs de formulaire | existant |
| `x-m.fab` | Action flottante (affichée seulement si le droit existe) | existant |
| `x-m.empty`, `x-m.skeleton-rows` | État vide ; chargement (annoncé `aria-busy`) | existant, enrichis |
| **`x-m.status-badge`** | Pastille d'état (`ok`, `warn`, `danger`, `brand`, `muted`) | **nouveau** |
| **`x-m.amount`** | Montant complet, signé, barré + « (annulé) » si annulé | **nouveau** |
| **`x-m.kv-list`** | Liste « libellé / valeur » ; omet les valeurs vides | **nouveau** |
| **`x-m.callout`** | Encadré `info` / `warn` / `danger` (`role="alert"` pour danger) | **nouveau** |
| **`x-m.sheet-action`** + **`x-m.confirm-sheet`** | Bouton + feuille de confirmation accessible (`role="dialog"`, `aria-modal`) ; l'action est celle de l'appelant (`wire:click`) | **nouveau** |
| **`x-m.filter-bar`** | Recherche + période + résumé annoncé (`aria-live`) + « Réinitialiser » si filtre actif | **nouveau** |
| **`x-m.access-note`** | Rappel « Consultation seule » pour le rôle `lecture_seule` | **nouveau** |

Classes ajoutées : `m-badge--muted`, `m-amount(.is-cancelled)`, `m-note`, `m-callout--info`, `m-danger-link--outline`,
`m-btn--danger`, `m-sheet__text/__actions`, `m-link`, `m-dim`, `m-empty__actions`.

## 4. États d'un écran de liste

| État | Comportement | Composant |
|---|---|---|
| Chargement d'un filtre | Liste estompée et non cliquable pendant la requête (`wire:loading.class="m-dim"`) | `m-dim` |
| Premier chargement lourd | Squelette de lignes | `x-m.skeleton-rows` |
| Vide (aucune donnée) | Message + bouton de création **si** le droit existe | `x-m.empty` |
| Vide filtré | « Aucune … pour ces filtres » + « Réinitialiser les filtres » | `x-m.empty` + `resetFilters()` |
| Consultation seule | Mention en tête, aucun bouton de création/modification | `x-m.access-note` |
| Refus | 403 serveur (écran inaccessible directement par URL) | middleware / `Gate` |
| Autre entreprise | 404 | scope d'entreprise |
| Hors connexion | Bandeau global ; données potentiellement périmées | layout |
| Erreur d'action | Toast d'erreur, aucune donnée modifiée | `dispatch('m-toast')` |

## 5. Clavier

Voir `docs/ANDROID-TEST-SCENARIOS.md` §3. Règles : `enterkeyhint` calculé par formulaire (`next` / `done` / `search` /
`enter`), Entrée = champ suivant, champ actif ramené au-dessus du clavier (`visualViewport`), `adjustResize` côté Android.

## 5 bis. Tablettes et grands écrans

L'application s'adapte à la **largeur réelle de la fenêtre** (pas au modèle d'appareil) : tablette, pliable, écran partagé,
téléphone en paysage. Tout est dans `mobile.css`, bloc « adaptatif », uniquement dans des media queries : un téléphone en
portrait (< 600 px) est strictement inchangé.

| Largeur | Comportement |
|---|---|
| < 600 px | Téléphone : mise en page d'origine |
| ≥ 600 px | Pleine largeur (plafond `--m-content-max` = 1100 px, centré). Le bandeau de KPI (`.m-strip`) et `.m-grid` deviennent des grilles `auto-fit` au lieu d'un défilement horizontal qui coupait les cartes. Feuilles (`.m-sheet`) et messages (`.m-toast`) centrés, 600 / 520 px au plus. Formulaires (`.m-form`) 720 px au plus, connexion (`.m-login`) 480 px |
| ≥ 900 px, ou téléphone en paysage (≥ 600 px de large, ≤ 520 px de haut) | La barre d'onglets du bas devient un **rail de navigation à gauche** (`--rail-w` = 88 px + zone sûre gauche, `--tab-h` passe à 0) |

Règles pour un nouvel écran : ne jamais fixer de largeur en px sur un conteneur de page ; utiliser `.m-grid` / `.m-strip` pour
des cartes ; ne pas compter sur `--tab-h` pour réserver la place de la barre (il vaut 0 avec le rail) ; centrer tout ce qui est
en `position: fixed` avec `left: calc(50% + var(--rail-w) / 2)`. Garde-fous : `tests/Feature/MobileResponsiveLayoutTest.php`.
Validation manuelle : aucun débordement horizontal sur 390, 600, 820, 1280, 1920 px et 844×390 (paysage) pour tous les écrans
`/m` accessibles au master ; écran natif de chargement/erreur vérifié sur une tablette simulée de 800 × 1280 dp.

## 6. Fiche de validation du pilote « Ventes UV »

Légende : ✅ fait · 🧪 testé automatiquement · 👁 vérifié dans un navigateur (viewport mobile) · 📄 vérifié statiquement (code/CSS) · 📱 exige un vrai appareil Android · 🐬 exige MySQL.

| Critère | Écrans | Preuve | Reste |
|---|---|---|---|
| Droits par rôle (master, admin, comptable, commercial, lecture seule) : accès, boutons visibles, actions Livewire forgées refusées | liste, fiche, création (la modification est testée mais hors pilote) | 🧪 `MobileVentesPilotTest` (28 cas) | — |
| Isolation entre entreprises (fiche, modification, recherche d'une vente étrangère) | liste, fiche, modification | 🧪 `MobileVentesPilotTest`, `TenantBoundaryRegressionTest` | — |
| États : vide, vide filtré, annulé, consultation seule, réinitialisation | liste, fiche | 🧪 + 👁 (320, 390 px ; lecture seule et master) | — |
| Pas de N+1 sur la liste | liste | 🧪 nombre de requêtes constant (8 lignes de plus), SQLite **et** MariaDB 12.3 | — |
| Composants (structure, accessibilité, échappement) | tous | 🧪 `MobileDesignSystemTest` (11 cas) | — |
| Contrastes AA clair + sombre | tous | 🧪 calculé sur les jetons | 📱 lisibilité en plein soleil |
| Zones tactiles ≥ 44 px, pas de débordement horizontal | liste, fiche, création | 👁 mesuré à 320, 390, 768 px (0 anomalie) | 📱 doigt réel |
| Feuilles de confirmation (annuler, supprimer) | fiche | 🧪 structure + 👁 ouverture/fermeture, boutons 54 px | 📱 bouton retour matériel fermant la feuille |
| Clavier du formulaire de création | création | 👁 `enterkeyhint`, 🧪 contrat | 📱 clavier réel (Gboard, Samsung) |
| Thème clair | tous | 📄 contrastes calculés | 👁 non rejoué : le navigateur de test impose le thème sombre |
| Moteur de base MySQL | liste (SQL brut `DATE()`, `SUM`) | 🐬✅ pilote + tout le mobile + cloisonnement exécutés sur MariaDB 12.3 (475 tests verts) | MySQL « officiel » (MariaDB n'est pas MySQL à 100 %) : à rejouer sur la version de production |

## 7. Inventaire des écrans mobiles

Voir **[MOBILE-INVENTORY-86.md](MOBILE-INVENTORY-86.md)** (inventaire factuel : nom, route, composant, rôles mesurés, permissions,
statut mobile, priorité, dépendances) et [`mobile-inventory.csv`](mobile-inventory.csv).

Cadrage : 78 écrans Master entreprise + 3 Espace Agent + 5 sécurité partagés = 86 ; Super Admin hors périmètre. Les chiffres
techniques (42 pages Livewire, 14 ressources CRUD, 9 exports) ne sont pas les « 24 écrans » : **ceux-ci ne sont pas encore
déterminés** et ne seront pas déduits. Aucun écran autre que Ventes UV n'est migré.
