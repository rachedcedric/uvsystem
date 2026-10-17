# Scénarios de test sur appareil Android réel

À exécuter à la main sur un téléphone physique (idéalement 2 modèles : un Samsung et un Pixel/Motorola, Android 11+
et 14+). **Tout ce qui figure ici est NON VÉRIFIÉ tant que la colonne « Résultat » n'est pas remplie.**
Les tests automatiques (navigateur, PHPUnit) ne couvrent ni le clavier réel, ni la caméra, ni les notifications
push, ni le bouton retour matériel, ni le téléchargement/partage de fichiers.

## 0. Préparer l'essai

| Quoi | Comment |
|---|---|
| Appareil | Débogage USB activé ; `adb devices` doit le lister (`~/Library/Android/sdk/platform-tools/adb`) |
| Serveur de démonstration | Voir [DEMO.md](DEMO.md). Il écoute **uniquement** `127.0.0.1:8770` : ne pas l'ouvrir au réseau local (comptes de démo). On le joint par USB : `adb reverse tcp:8770 tcp:8770` |
| APK de debug | JDK 21 (`/opt/homebrew/opt/openjdk@21/libexec/openjdk.jdk/Contents/Home`), `ANDROID_HOME=~/Library/Android/sdk`. `npm run build && npx cap sync android`, puis dans `android/` : `./gradlew assembleDebug`. Fichier : `android/app/build/outputs/apk/debug/app-debug.apk` (**celui du 5 octobre est périmé : le reconstruire**, le manifeste a changé : `adjustResize`) |
| Adresse de l'app | Après `cap sync`, remplacer `server.url` par `http://localhost:8770/m` dans `android/app/src/main/assets/capacitor.config.json` (le réglage debug autorise le HTTP vers `localhost` et `10.0.2.2`). **Ne pas committer ce changement**, ne pas l'utiliser en release |
| Installation | `adb install -r app-debug.apk` (si une version « release » est déjà installée : `adb uninstall ci.uvmaster.app` d'abord) |
| Comptes | `master@demo.test`, `lecture@demo.test`, `superadmin@demo.test` (mots de passe transmis hors dépôt). Pour comptable/commercial/admin : les créer depuis l'écran Utilisateurs du master |
| Noter | Modèle, version Android, clavier utilisé (Gboard / Samsung Keyboard), taille de police système, thème clair/sombre |

### APK de validation reconstruit (2026-10-08)

| Élément | Valeur |
|---|---|
| Fichier | `android/app/build/outputs/apk/debug/app-debug.apk` (≈ 10,6 Mo, build **local**, non versionné par git) |
| Empreinte SHA-256 (début) | `cd89ea3b7fc05815…` |
| Identité | `ci.uvmaster.app`, versionCode 14, versionName 4.0 (inchangés : un build de debug ne change pas la version) |
| Clavier | `windowSoftInputMode = adjustResize` **présent** (vérifié dans le manifeste compilé) |
| Adresse embarquée | `http://localhost:8770/m`, HTTP autorisé : **réservé à la validation via `adb reverse`** ; fichier `assets/capacitor.config.json` ignoré par git |
| Réseau | configuration de debug : HTTP autorisé vers `localhost` et `10.0.2.2` uniquement |
| Release signée | **non reconstruite** (clé de signature et numéro de version à décider avant publication ; l'APK de release existant est périmé) |
| Installation | **non faite** : aucun appareil connecté (`adb devices` vide) |

Installation : `adb reverse tcp:8770 tcp:8770` puis `adb install -r android/app/build/outputs/apk/debug/app-debug.apk`.
Le serveur de démonstration doit tourner sur ce poste (voir [DEMO.md](DEMO.md)).

Légende résultat : ☐ à faire · ✅ OK · ❌ échec (décrire) · ➖ sans objet.

### Campagne du 2026-10-17 (émulateur, pas un téléphone réel)

Exécutée sur l'émulateur Android 16 `Beni_Test` (Gboard, français côté site, anglais côté système), contre une démo
locale (`adb reverse`). **Ne remplace pas un essai sur téléphone physique** : les lignes restées ☐ en ont besoin.
Défauts trouvés et corrigés pendant la campagne : titres « Nouveau agent / catégorie / vente / charge », pastilles de
caisse invisibles, total de caisse écrasé, lien d'historique pleine largeur, `0 Ko`, messages de validation en
anglais, déconnexion depuis une page de sécurité renvoyant vers la connexion bureau, 200 % de police, barre d'état en
thème sombre, boîte « This page has expired » en anglais, erreur JavaScript sur le tableau de bord (blocs différés en
`<section>`), jeton push d'un téléphone partagé entre deux entreprises (erreur 500), carte de bienvenue de l'espace agent blanche sur blanc, menu latéral Filament ouvert sur téléphone, pastille de la cloche sur l'icône, mot de passe : une seule règle (12 caractères, majuscule, minuscule, chiffre, symbole).

## 1. Installation et lancement

| ID | Étapes | Attendu | Résultat |
|---|---|---|---|
| A1 | Installer, ouvrir | Splash puis écran de connexion, pas d'écran blanc | ✅ |
| A2 | Couper le réseau, relancer l'app | Page hors-ligne de repli ; au retour du réseau, rechargement possible | ✅ |
| A3 | Rotation (si autorisée) | Mise en page correcte, rien de coupé | ✅ paysage OK |
| A4 | Police système à 200 % | Textes lisibles, boutons non écrasés | ✅ 200 % rognait le solde et les onglets : plafonné à 150 % (APK) |

## 2. Connexion et rôles

| ID | Étapes | Attendu | Résultat |
|---|---|---|---|
| B1 | Connexion master | Accueil, onglets Agents / Ventes / Accueil / Caisse / Plus | ✅ |
| B2 | Connexion `lecture@demo.test` | Accueil **sans** bouton « Nouveau » ; Plus sans Import, Abonnement, Profil, Utilisateurs, Sauvegardes ; badge « Lecture seule » | ✅ |
| B3 | Lecture seule : taper à la main `/m/ventes/nouvelle`, `/m/import`, `/m/exports/commissions/pdf` | Refus (403), aucune donnée ni formulaire affiché ; l'app reste utilisable (retour possible) | ✅ page 403 française |
| B4 | Connexion `superadmin@demo.test` sur l'app mobile | Refusée (le super admin n'a pas d'accès aux écrans entreprise) | ✅ |
| B5 | Mauvais mot de passe 5 fois | Message d'erreur puis limitation, jamais de crash | ✅ limitation après 5 essais |
| B6 | Fermer/relancer l'app après connexion | Session conservée (« rester connecté ») | ✅ |
| B7 | Déconnexion depuis Plus | Retour à la connexion ; bouton retour ne ramène pas à une page protégée | ✅ |

## 3. Clavier (nouveau code : `enterkeyhint`, Entrée = suivant, champ visible)

Formulaires à parcourir : **Profil de l'entreprise** (11 champs), **Nouvelle vente**, **Nouveau remboursement**,
**Nouvelle charge**, **Mouvement de caisse** (multi-lignes), **Utilisateur** (création, mot de passe), **Connexion**,
**Code 2FA**, **Recherche** (Agents).

| ID | Étapes | Attendu | Résultat |
|---|---|---|---|
| C1 | Toucher un champ texte non final | La touche d'action du clavier affiche « Suivant » (flèche) | ✅ |
| C2 | Appuyer sur cette touche | Le focus passe au champ suivant, **le formulaire n'est pas envoyé** | ✅ |
| C3 | Dernier champ : touche d'action | Libellé « OK/Terminé » ; le clavier se ferme ; pas de double envoi | ✅ |
| C4 | Champ de recherche | Touche « Rechercher » ; appui = clavier fermé, liste filtrée | ✅ |
| C5 | Zone multiligne (adresse, pied de page) | La touche fait un retour à la ligne (pas « Suivant ») | ✅ |
| C6 | Champ du bas de page, clavier ouvert | Le champ **n'est pas masqué** par le clavier ni par la barre « Enregistrer » ; il remonte tout seul | ✅ |
| C7 | Idem avec la barre « Enregistrer » collée en bas | Elle reste visible au-dessus du clavier (pas recouverte, pas flottante au milieu) | ✅ |
| C8 | Montant (vente, remboursement) | Clavier **numérique**, affichage « 1 250 000 », seuls les chiffres sont gardés, bouton rapide OK | ✅ |
| C9 | E-mail | Clavier avec `@` ; pas de majuscule automatique gênante | ✅ |
| C10 | Téléphone | Clavier de numérotation | ✅ |
| C11 | Mot de passe | Masqué, bouton œil, proposition d'enregistrement par le gestionnaire de mots de passe | ✅ |
| C12 | Code 2FA reçu par e-mail/SMS | Clavier numérique ; suggestion « code reçu » au-dessus du clavier si disponible | ✅ code 2FA : clavier numérique, envoi auto à 6 chiffres |
| C13 | Fermer le clavier avec la touche retour système | Le clavier se ferme **sans quitter la page** ; un 2ᵉ appui revient en arrière normalement | ✅ |
| C14 | Clavier flottant / divisé (Samsung) ou mode une main | Le champ actif reste visible | ☐ clavier flottant : téléphone réel |
| C15 | Changer de clavier (Gboard ↔ Samsung ↔ SwiftKey) | Mêmes résultats C1 à C6 ; noter tout écart par clavier | ☐ autre clavier : téléphone réel (émulateur : Gboard seul) |
| C16 | Saisir, mettre l'app en arrière-plan, revenir | Les valeurs saisies sont conservées, clavier fermé | ✅ |
| C17 | Appui long / copier-coller dans un champ | Fonctionne ; le montant se reformate | ✅ |

> Rappel : `windowSoftInputMode=adjustResize` (manifeste) ne prend effet qu'avec un **APK reconstruit** ; le reste (touches
> d'action, défilement) vient du site. Si C6/C7 échouent seulement sur l'APK ancien, c'est attendu.

## 4. Abonnement et profil

| ID | Étapes | Attendu | Résultat |
|---|---|---|---|
| D1 | Démo (échéance à J-3) : ouvrir n'importe quel écran | Bandeau jaune « Échéance proche », cliquable (zone ≥ 44 px) vers « Mon abonnement » | ✅ |
| D2 | Mettre l'abonnement en expiré (voir DEMO.md) | Bandeau rouge « consultation seule » ; lecture et exports possibles ; tenter d'enregistrer une vente : message clair, aucune donnée créée | ✅ message clair, rien créé |
| D3 | Profil de l'entreprise : changer ville + pied de page, enregistrer | Message de succès ; valeurs conservées à la réouverture | ✅ |
| D4 | Logo : « Choisir une image » → **appareil photo** puis **galerie** | Permission caméra demandée ; photo prise, « sera réduit automatiquement » ; après enregistrement le logo apparaît dans Plus | ✅ |
| D5 | Logo : image > 2 Mo ou SVG | Message d'erreur lisible, rien d'enregistré | ✅ image de 3 Mo refusée (« téléversement échoué ») ; SVG refusé côté serveur (Pest) |

## 5. Sessions et appareils

| ID | Étapes | Attendu | Résultat |
|---|---|---|---|
| E1 | Plus → Sécurité → « Appareils connectés » | La session de ce téléphone est marquée « actuelle » ; la page est lisible sur mobile | ✅ |
| E2 | Se connecter avec le même compte sur un navigateur de bureau ; fermer cette session depuis le téléphone | Le navigateur est déconnecté au prochain clic ; le téléphone reste connecté | ✅ |
| E3 | Changer le mot de passe depuis le navigateur | Le téléphone est déconnecté (nouvelle connexion exigée) | ✅ |
| E4 | « Déconnecter tous les autres appareils » | Mot de passe redemandé ; seul ce téléphone reste | ✅ |
| E5 | Se connecter sur 2 téléphones avec 2 comptes de **deux entreprises** | Chacun ne voit que ses propres sessions | ➖ couvert par Pest (TenantIsolationTest) ; G1-G3 vérifiés sur appareil |

## 6. Navigation, fichiers, notifications

| ID | Étapes | Attendu | Résultat |
|---|---|---|---|
| F1 | Bouton retour matériel : feuille ouverte → modale → racine d'onglet → accueil → quitter | Chaque appui ferme/recule d'un niveau ; confirmation avant de quitter l'accueil | ✅ |
| F2 | Master lié à une fiche agent : Plus → « Mon espace agent » | S'ouvre ; lien « ← Application » visible ; le retour matériel fonctionne | ✅ s'ouvre avec « ← Application » ; menu latéral refermé et carte de bienvenue rendue visible (corrigés) |
| F3 | Master : exporter un PDF (ventes du mois) et un Excel | Téléchargement + feuille de partage ; fichier ouvrable ; pied de page de l'entreprise présent sur le PDF | ✅ |
| F4 | Lecture seule : chercher un bouton d'export | Aucun ; l'URL directe est refusée (B3) | ✅ |
| F5 | Notifications push (master/admin) : app ouverte / en arrière-plan / fermée | Réception, son, toucher ouvre l'écran Notifications ; les autres rôles ne reçoivent rien | ☐ push : vérifié lors d'une campagne précédente, non rejoué ; jeton de téléphone partagé corrigé |
| F6 | Liens externes (site, e-mail, téléphone) | S'ouvrent dans le navigateur/appli système, pas dans la WebView | ✅ tel: ouvre le composeur ; « version complète » s'ouvre dans la WebView avec « ← Application » |
| F7 | Tirer pour rafraîchir ; bandeau « Hors connexion » en coupant le Wi-Fi | Conforme, sans fausse alerte avec réseau faible | ✅ |

## 7. Isolation entre entreprises (sur appareil)

| ID | Étapes | Attendu | Résultat |
|---|---|---|---|
| G1 | Connecté dans l'entreprise A, saisir à la main l'adresse d'une fiche d'agent de B (`/m/agents/<id de B>`) | Page « introuvable » (404), aucune donnée de B | ✅ 404 |
| G2 | Rechercher dans les agents le nom d'un agent de B | Aucun résultat | ✅ |
| G3 | Changer de compte (A puis B) sur le même téléphone | Aucune donnée de A visible dans B (pas de cache) | ✅ jeton push repris par le nouveau compte (corrigé) |

## 8. Confort et accessibilité

| ID | Étapes | Attendu | Résultat |
|---|---|---|---|
| H1 | Thème sombre du système | Lisible, bandeaux visibles | ✅ (barre d'état corrigée au changement de thème) |
| H2 | Petit écran (≈ 320 px) et grand écran | Aucun défilement horizontal | ✅ 320 dp : aucun défilement horizontal |
| H3 | Zones tactiles | Boutons/lignes faciles à toucher, pas d'appui accidentel | ✅ aucune cible < 40 px, sauf les barres du graphique du mois (19 px de large, 31 jours) |
| H4 | Lecteur d'écran (TalkBack) sur la connexion | Libellés des champs annoncés | ☐ TalkBack : téléphone réel |

## 9. Conditions réelles et affichage 320 / 390 px (exigences du cadrage)

Un émulateur **ne remplace pas** ces essais : ils se font sur un téléphone physique.

| ID | Étapes | Attendu | Résultat |
|---|---|---|---|
| R1 | Largeur 390 px : Réglages → Affichage → Taille d'affichage (ou `adb shell wm size 780x1688` + `adb shell wm density 320` (400 donnerait 312 px, pas 390)) ; remettre ensuite `adb shell wm size reset` et `adb shell wm density reset` | Ventes UV (liste, fiche, création) lisible, aucun défilement horizontal, cibles tactiles confortables | ✅ 390 px : aucun défilement horizontal, aucune cible < 40 px (liste, fiche, création) |
| R2 | Largeur 320 px : `adb shell wm size 640x1280` + `adb shell wm density 320` (puis reset) | Idem ; puces de période, bandeau d'abonnement et feuilles de confirmation non coupés | ✅ 320 px : rien de coupé, feuille de confirmation entière |
| R3 | Réseau faible (3G/Edge) pendant une recherche et un enregistrement | Liste estompée pendant le chargement, aucun double envoi, message clair en cas d'échec | ✅ serveur ralenti à 3,5 s : liste estompée, 3 appuis rapides = 1 seule vente ; échec réseau : bouton figé et sans message → corrigé (message « Connexion perdue… », bouton réactivé) |
| R4 | Mode avion en plein formulaire, puis retour du réseau | Bandeau « Hors connexion » ; valeurs saisies conservées ; l'enregistrement aboutit une seule fois | ✅ valeurs conservées, bandeau « Hors connexion », une seule vente après le retour (émulateur : le mode avion ne coupe pas le tunnel `adb reverse`, essai refait en arrêtant le serveur) |
| R5 | Passage Wi-Fi → 4G pendant l'usage | Session conservée, aucune déconnexion | ✅ session conservée (limite émulateur : le lien serveur passe par le tunnel) |
| R6 | Écran verrouillé 2 min puis reprise ; app en arrière-plan 10 min | Reprise sur le même écran, pas d'écran blanc ; session conservée | ✅ verrouillé 2 min puis arrière-plan 10 min : même écran, valeur saisie conservée, session conservée |
| R7 | Appel entrant ou notification pendant la saisie | Retour au formulaire intact | ✅ appel entrant simulé : saisie « 75 000 » et focus intacts |
| R8 | Économiseur de batterie activé | Navigation fluide, sons et vibrations conformes aux réglages | ✅ navigation normale avec l'économiseur ; sons et vibrations non vérifiables sur émulateur |
| R9 | Autorisation caméra refusée puis accordée (logo, justificatif) | Message clair si refus ; fonctionne après autorisation | ✅ caméra s'ouvre après autorisation ; refus : message mêlé d'anglais et sans consigne → corrigé (réglages > Applications > UVMASTER > Autorisations) |
| R10 | Heure du téléphone décalée de +2 jours | Aucune faille d'accès : l'abonnement est jugé côté serveur (bandeau inchangé) | ✅ horloge +2 jours : affichage identique (date serveur), session intacte |
| R11 | Luminosité maximale en extérieur | Textes discrets et pastilles lisibles (contrastes AA calculés, à confirmer à l'œil) | ☐ à confirmer à l'œil sur téléphone réel (contrastes AA verrouillés par test) |

## 10. Critères de passage (règle de décision)

| Statut | Quand |
|---|---|
| 🔴 **BLOQUANT** | Champ masqué par le clavier sans pouvoir l'atteindre ; feuille de confirmation qui ne s'ouvre pas, ne se ferme pas ou dont le bouton retour matériel quitte l'app ; compte lecture seule qui voit un bouton d'écriture ou accède à un écran interdit ; **données d'une autre entreprise visibles** ; perte de données saisies ; crash |
| 🟠 **À VALIDER** | Écart d'ergonomie sans perte ni risque (libellé de touche différent selon le clavier, animation saccadée) ; à noter et à trancher |
| 🟢 **VALIDÉ** | Scénario exécuté sur appareil physique, résultat conforme, appareil et clavier notés |
| ⚪ **NON DÉTERMINÉ** | Non exécuté, ou résultat ambigu. **Jamais compté comme réussi** |

Rattachement aux exigences : clavier réel = §3 ; tactile = H3, R1, R2 ; bouton retour matériel = F1 + C13 ; feuilles de confirmation =
F1 + scénarios « Ventes » ci-dessous ; navigation = F2, F6 ; lecture seule = B2, B3, F4 ; Master = B1, D1 à D5 ; 320/390 px = R1, R2 ;
conditions réelles = R3 à R11.

## 11. Pilote « Ventes UV » (liste, fiche, création) à passer sur l'appareil

| ID | Étapes | Attendu | Résultat |
|---|---|---|---|
| V1 | Master : Ventes → liste | Cartes de période, top 5, filtres ; chaque ligne ≥ 44 px ; bouton « Nouvelle vente » présent | ✅ |
| V2 | Rechercher un nom inexistant | « Aucune vente pour ces filtres » + « Réinitialiser les filtres » ; le bouton remet « 7 jours » et vide la recherche | ✅ le bouton « Réinitialiser les filtres » vide la recherche ; le doublon avec le bouton flottant a été supprimé |
| V3 | Ouvrir une vente → « Annuler cette vente » | Feuille de confirmation ; touche retour matériel **ferme la feuille** sans quitter la page ; « Non, garder » ne modifie rien | ✅ retour matériel ferme la feuille, « Non, garder » ne change rien |
| V4 | Confirmer l'annulation d'une vente de test | Message de succès ; montant barré + pastille « Annulée » ; plus d'action d'annulation | ✅ montant barré + pastille « Annulée », plus d'action d'annulation |
| V5 | Nouvelle vente : choisir un agent, saisir un montant (touches « Suivant »), enregistrer | Clavier numérique pour le montant ; champ jamais masqué ; une seule vente créée | ✅ clavier numérique « 25 000 », champ visible, une seule vente créée |
| V6 | Lecture seule : liste puis fiche | Mention « Consultation seule », aucun bouton d'écriture ; `/m/ventes/nouvelle` à la main = refus | ✅ « Consultation seule », aucun bouton d'écriture, `/m/ventes/nouvelle` = 403 français |
| V7 | Commercial : fiche d'une vente | Peut annuler/modifier selon ses droits, ne peut pas supprimer | ✅ dupliquer, annuler et modifier ; pas de suppression |
| V8 | Compte d'une autre entreprise : saisir l'adresse d'une vente de la première | Page introuvable, aucune donnée | ✅ « Page introuvable », aucune donnée de l'autre entreprise |

## Fiche de synthèse (à remplir)

| Appareil | Android | Clavier | APK (debug/release) | Tests ✅ | Tests ❌ | Remarques |
|---|---|---|---|---|---|---|
| | | | | | | |
