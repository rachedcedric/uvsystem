# CMS de la plateforme

Le super admin modifie les contenus publics du SaaS depuis `/platform`, groupe **CMS** du menu. **Aucun code à toucher.**

| Entrée du menu | Contenu modifiable |
|---|---|
| Page d'accueil | accroche, titre et texte d'introduction, libellés des boutons, titres des sections, slogan du pied de page |
| Pages publiques | `/offres` (titre, sous-titre, étiquette « recommandée », boutons), inscription (titre, mention d'essai, bouton), pages « après l'envoi » et « confirmation » |
| E-mails et messages | formule d'appel, bouton, e-mail de confirmation d'inscription et les 11 e-mails d'abonnement (objet et corps, avec repères `{plan}`, `{date}`, `{days}`, `{amount}`, `{cycle}`, `{company}`, `{hours}`) |
| Réglages du SaaS | nom de la marque, raison sociale, e-mail et téléphone de contact, liens des conditions d'utilisation et de la confidentialité |

## Principes
- **Valeurs d'origine toujours disponibles** : les textes du code sont les valeurs par défaut (`App\Cms\ContentRegistry`). Seuls les contenus modifiés sont enregistrés (table `cms_entries`) ; un champ vide ou identique à l'origine **rétablit** l'origine ; « Tout rétablir » le fait pour toute la section.
- **Registre fermé** : seules les clés du registre sont modifiables. Le CMS ne peut ni créer de clé, ni changer autre chose que du contenu.
- **Texte brut** : les balises HTML saisies sont retirées à l'enregistrement et tout est échappé à l'affichage (un saut de ligne devient un retour à la ligne). Liens : `https://…` ou chemin du site ; e-mail et téléphone validés.
- **Jamais bloquant** : si la table est absente (avant migration) ou la base indisponible, les pages publiques s'affichent avec les valeurs par défaut.
- **Sécurité** : accès réservé à `User::isSuperAdmin()` (page, enregistrement et réinitialisation) ; contenu global (pas de `tenant_id`), invisible et non modifiable par les entreprises.
- **Traçabilité** : chaque modification est consignée dans le « Journal plateforme » (clé, ancienne et nouvelle valeur).

## Pour l'utiliser dans une vue
`{{ cms('landing.cta.primary') }}` (texte), `{{ cms_html('landing.hero.title') }}` (multiligne, échappé), `cms_lines('emails.x.body')`. Toute nouvelle clé doit être déclarée dans `ContentRegistry` (un test échoue sinon).

## Ce qui n'est pas dans le CMS
- **Logo et couleurs** : non éditables (ils sont utilisés dans tous les écrans de connexion, la console et l'application mobile ; les rendre éditables demande un traitement des fichiers et de l'ensemble des thèmes).
- **Prix, limites et fonctionnalités des offres** : gérés dans « Offres ».
- **Page d'accueil** : seuls les textes listés sont éditables (les rubriques détaillées, cartes et illustrations restent dans le code). Le branchement de `welcome.blade.php` sur le CMS est dans le fichier de la page d'accueil.
