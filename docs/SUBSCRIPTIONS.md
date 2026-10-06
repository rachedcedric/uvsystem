# Offres et abonnements

Système d'abonnement SaaS ajouté au multi-tenant (voir `SAAS-MULTITENANT.md`). **Aucun déploiement n'a été effectué.**

## Modèle

| Élément | Portée | Rôle |
|---|---|---|
| `SubscriptionPlan` | **global** (pas de `tenant_id`) | catalogue d'offres, géré par `/platform` |
| `TenantSubscription` | par tenant | une ligne par période d'abonnement ; **jamais supprimée ni réécrite** pour un changement d'offre ou de cycle |
| `SubscriptionPayment` | par tenant | paiement (en attente / confirmé / échoué) |
| `SubscriptionNotificationLog` | par tenant | journal anti-doublon des notifications |
| Enums | `SubscriptionCycle` (monthly, yearly), `SubscriptionStatus` (trialing, active, suspended, expired, cancelled, replaced), `SubscriptionAccessLevel` |

- Montants : FCFA, **entiers**. Un maximum `NULL` = **illimité** (jamais 0 ; l'écran refuse 0).
- Un seul abonnement **courant** par tenant : colonne `current_marker` (= `tenant_id` pour la ligne courante, `NULL` pour l'historique), unique en base. Les opérations passent par `SubscriptionManager` (transaction + `lockForUpdate`).
- `features` : liste JSON d'identifiants. `toutes_les_fonctions_{slug}` inclut les fonctionnalités de l'offre `{slug}` (Pro inclut Essentiel, Entreprise inclut Pro).

## Services (`app/Services/Subscriptions/`)

- `SubscriptionManager` : démarrer un essai (un seul par tenant sauf décision explicite), activer, changer d'offre/de cycle, renouveler, suspendre, réactiver, annuler, expirer, prolonger l'essai, historique.
- `SubscriptionEntitlements` : **point unique de décision** (`hasFeature`, `canCreateUser`, `canCreateAgent`, usages, limites, restes, `isUnlimited`). Sans argument il travaille sur le tenant courant (`TenantContext`).
- `SubscriptionAccessPolicy` : **politique d'expiration centralisée** (voir ci-dessous).
- `SubscriptionBilling` + `SubscriptionPaymentGateway` (contrat) + `ManualSubscriptionPaymentGateway` : voir « Paiements ».
- `SubscriptionNotifier`, `SubscriptionLifecycleProcessor`, `SubscriptionMetrics`, `TenantSignupService`, `FeatureCatalog`.

## Application côté serveur

Le refus a lieu **dans les modèles** (trait `BelongsToTenant`), donc Filament, Livewire, `/m`, API, imports, commandes et services sont tous couverts ; un bouton masqué n'est jamais la protection.

- **Limites** (`User`, `Agent`) : création refusée (`SubscriptionLimitReached`, 422 en API) ; la restauration d'un agent supprimé est recomptée. Rétrograder ne supprime rien : les dépassements existants restent, seule la création est bloquée. **Atomicité** : le comptage et l'insertion se font dans une transaction qui verrouille la ligne du tenant (`SELECT … FOR UPDATE`) ; deux créations simultanées passent l'une après l'autre.
- **Stockage** (`storage_limit_mb`) : un justificatif de charge qui dépasse l'espace de l'offre est refusé et ce seul fichier est retiré ; un rapport planifié qui dépasserait n'est pas enregistré. Aucun fichier existant n'est jamais supprimé.

### Tableau d'application des fonctionnalités

| Fonctionnalité | Où elle est appliquée (côté serveur) | Preuve |
|---|---|---|
| `statistiques_essentielles` | `m.statistiques`, page Filament Statistiques | `SubscriptionFeaturesTest::test_statistiques_essentielles` |
| `statistiques_avancees` | page Filament « Rapports & Statistiques » | `…::test_statistiques_avancees` |
| `exports_pdf`, `exports_excel` | **toute** génération PDF/Excel (Filament, `/m`, API, commandes) via `GatedPdf`/`GatedExcel` + `ExportGate` ; PDF = `exports_pdf`, Excel/CSV = `exports_excel` | `…::test_exports_pdf_and_excel`, `…::test_every_pdf_and_excel_generation_goes_through_the_gate…` |
| `exports_avances` | commissions, caisse (jour/historique), fiche agent, exports par période | `…::test_exports_avances` |
| `rapports_avances` | rapports quotidiens et planifiés (`uv:daily-report`, `reports:generate`) : le tenant est ignoré s'il ne l'a pas | `…::test_rapports_avances` |
| `notifications_dettes` | alertes, rappels, SMS de dettes : `via()` des notifications (tous les points d'envoi) | `…::test_notifications_dettes` |
| `notifications_push` | canal Firebase + enregistrement du jeton push | `…::test_notifications_push` |
| `scoring_agents` | scoring Filament et `/m` | `SubscriptionTenantAccessTest::test_the_mobile_import_and_scoring_depend_on_the_plan_features` |
| `import_donnees` | import Filament et `/m` (page et action) | idem |
| `sauvegardes_dediees` | commande `tenancy:backup-dedicated` (hebdomadaire, rotation de 7) + téléchargement `/exports/mes-sauvegardes/{fichier}` (master) | `…::test_sauvegardes_dediees`, `…::test_dedicated_backups_are_rotated` |
| `assistance_prioritaire`, `options_personnalisees` | **fonctionnalités de service** (aucun écran à verrouiller) : exposées par `hasFeature()` mais sans blocage applicatif | `…::test_every_enforced_feature_has_a_proof_and_service_features_are_declared` |

Un test d'inventaire échoue si une fonctionnalité est ajoutée au catalogue sans preuve d'application. Les exports de listes (`exports_pdf`, `exports_excel`) restent permis en accès restreint ou sans abonnement : c'est la récupération des données.

## Politique d'expiration

| État | Niveau | Effet |
|---|---|---|
| Tenant suspendu par la plateforme | Bloqué | accès coupé (sessions et jetons révoqués), comme avant |
| Essai/abonnement actif non échu ; annulé mais période payée en cours | Plein | tout |
| Expiré, annulé échu, suspendu (abonnement) | **Restreint** | lecture, exports, page d'abonnement ; **aucune écriture métier** ; création d'utilisateurs/agents refusée |
| Aucune ligne d'abonnement | **Restreint** (fail closed, par défaut) | `SUBSCRIPTION_REQUIRED=false` rétablit l'ancien mode « hérité » (illimité), réservé aux bases de test |

Rien n'est supprimé à l'expiration (données, utilisateurs, agents, historiques). Le statut est dérivé des **dates** : l'accès ne dépend jamais du passage du cron. Les écritures du code serveur de confiance (`runAs`, commandes) ne sont pas bridées, **sauf** la création d'utilisateurs et d'agents. Exemptés du blocage d'écriture : comptes (2FA, mot de passe), journal d'activité, jetons push, soldes dérivés.

Changement par rapport à avant : `Tenant::isActive()` ne dépend plus de `trial_ends_at` ; un essai expiré ne coupe plus l'accès, il restreint les écritures.

## Paiements

Aucun prestataire n'est installé. Un paiement n'est `confirmed` que sur une **confirmation réelle** : mode manuel = attestation d'un `isSuperAdmin()` avec **référence obligatoire**. `SubscriptionBilling::confirm` est idempotent (un paiement ne prolonge qu'une fois) ; un échec ne modifie pas l'abonnement. Ajouter CinetPay/PayDunya plus tard = une classe implémentant `SubscriptionPaymentGateway` déclarée dans `config/subscriptions.php` (`gateways`).

## Changement d'offre à la demande du tenant, prorata, renouvellement automatique

- **Demande** (`/m/abonnement`, master/admin) : `SubscriptionBilling::requestChange`. Le serveur calcule le **prorata** (`SubscriptionProration`) : crédit = prix payé × part non consommée ; même cycle : coût = nouveau prix × part restante, échéance conservée ; cycle changé : prix complet d'une nouvelle période ; à régler = max(0, coût − crédit). Rien à régler (rétrogradation, essai, abonnement hérité) : le changement est **appliqué tout de suite**. Sinon un paiement en attente du montant dû est créé et le changement s'applique à sa **confirmation réelle** (passerelle, ou super admin avec référence depuis « Demandes de changement »). Une seule demande en attente ; la précédente est annulée **avec son paiement**, et un paiement dont la demande n'est plus valable ne peut pas être confirmé.
- Un abonnement échu ne change pas d'offre : on règle d'abord son renouvellement.
- **`auto_renew`** : avec la passerelle manuelle aucun prélèvement n'est possible ; l'option fait **émettre une facture de renouvellement** (paiement en attente + notification `SubscriptionRenewalDue`) à l'échéance, une seule fois ; payer cette facture réactive l'abonnement. Activable par le master (`/m/abonnement`) ou la plateforme.

## Expiration planifiée

`php artisan subscriptions:process` (planifiée tous les jours à 01h00 dans `routes/console.php`, avec `withoutOverlapping` et `onOneServer`) : expire les essais/abonnements échus, avertit à J-7, J-3, J-1. Idempotente : l'expiration n'agit qu'une fois et chaque avertissement a une clé unique (abonnement + échéance + palier). Une erreur sur un tenant est journalisée et n'arrête pas les autres ; code de sortie 1 s'il y en a eu. Notifications : `app/Notifications/Subscriptions/` (mail + base), envoyées aux masters, **de façon synchrone** (non mises en file : un échec n'est jamais perdu dans un worker). Chaque envoi est réservé par une mise à jour atomique ; un échec est **conservé** (`failed_at`, `last_error`, `attempts`), n'altère jamais l'état métier, est **repris** au passage suivant jusqu'à `notification_max_attempts` (5), et reste consultable ensuite. Le rapport de la commande indique expirations, avertissements, factures, reprises, échecs d'envoi et erreurs.

## Interfaces

- `/platform` : **Offres** (`SubscriptionPlanResource`), **Abonnements** (`TenantSubscriptionResource` : attribuer, changer d'offre, prolonger l'essai, enregistrer un paiement, suspendre, réactiver, annuler, historique, filtres statut/offre/cycle/échéance/tenant), tableau de bord (tenants, essais, actifs, expirés, suspendus, MRR/ARR estimés, répartition, expirations à 7 jours). Accès par `User::isSuperAdmin()` (rôle **et** aucun tenant) à chaque niveau : `canAccess`, policies, action revérifiée dans le handler.
- **MRR/ARR** : revenu récurrent **estimé**, pas encaissé. Mensuel : MRR = prix, ARR = prix × 12. Annuel : MRR = prix / 12, ARR = prix. Seuls les abonnements actifs comptent, au prix figé à la souscription ; les abonnements hérités (prix 0) ne pèsent pas. Calcul en mémoire (centaines de tenants).
- `/offres` (public) : offres actives uniquement, bascule Mensuel/Annuel, prix et limites lus en base.
- `/souscrire` (public), en **deux temps** : (1) formulaire → un lien signé, à usage unique et valable 24 h est envoyé par e-mail, **rien n'est créé** ; (2) au clic de confirmation (un simple GET ne crée rien : les scanners de liens e-mail ne consomment pas le jeton), l'entreprise, son master et l'essai sont créés après nouveau contrôle. Seul le hash du lien est stocké ; la charge utile est chiffrée, le mot de passe déjà haché, le tout vidé après usage. Le serveur recalcule offre, prix, limites, essai, rôle (toujours `master`). Anti-abus d'essais : adresse **normalisée** (minuscules, sans `+alias`, sans points Gmail) unique parmi inscriptions vérifiées, comptes et tenants ; téléphone unique ; domaines jetables refusés ; 3 inscriptions vérifiées par IP et par jour ; 3 e-mails par adresse et par heure ; leurre anti-robot et délai minimum de 3 s ; jeton de formulaire à usage unique ; 5 envois par heure et par IP ; mot de passe 12 caractères (maj., min., chiffres) ; conditions obligatoires ; un compte déjà rattaché à une entreprise est refusé. Limite inhérente : plusieurs vraies boîtes mail distinctes restent possibles.
- `/m/abonnement` (master/admin du tenant courant) : offre, statut, cycle, prix, échéance, jours restants, essai, utilisation, fonctionnalités, avertissements. Accessible aussi en accès restreint.

## Migration `2026_10_09_000000_create_subscription_tables`

- **Ajoute** 6 tables (offres, abonnements, paiements, journal de notifications, demandes de changement, inscriptions publiques) et les 3 offres ; **crée un abonnement par tenant existant** d'après `tenants.plan` (insensible à la casse ; inconnu ou vide : `SUBSCRIPTIONS_LEGACY_PLAN`, par défaut `entreprise` = illimité). Essai futur : `trialing` ; essai passé : `expired` ; sinon `active` sans échéance ; prix appliqué 0 (donc pas de MRR) ; `metadata.legacy = true`.
- **Ne supprime et ne modifie rien** : `tenants.plan` et `tenants.trial_ends_at` restent (champs **obsolètes**, recopiés en miroir par le manager, jamais lus). Leur suppression fera l'objet d'une migration distincte après validation complète.
- Passe **après** la migration multi-tenant ; sur une base de production elle ne s'exécute qu'après le contrôle préalable `tenancy:preflight` (la migration multi-tenant reste bloquée tant que celui-ci n'est pas validé).
- **Retour arrière** : `php artisan migrate:rollback --step=1` supprime uniquement les 6 nouvelles tables (l'historique d'abonnements est perdu) ; rien d'autre n'est touché.

## Déploiement (à NE PAS exécuter sans validation explicite)

1. Sauvegarde vérifiée de la base et de `storage/app/private`.
2. Étapes de `SAAS-MULTITENANT.md` (contrôle préalable, migration multi-tenant) si elles n'ont pas déjà été faites.
3. Envoyer le code, `composer install --no-dev -o`, `npm run build` (ou envoyer `public/build`).
4. `php artisan down` puis `php artisan migrate --force` puis `php artisan optimize:clear` puis `php artisan up`.
5. Vérifier **avant d'ouvrir le service** que tout tenant a un abonnement : `select t.id from tenants t left join tenant_subscriptions s on s.tenant_id = t.id and s.current_marker is not null where s.id is null` → vide (la politique par défaut `SUBSCRIPTION_REQUIRED=true` met en accès restreint un tenant sans abonnement). Puis : un abonnement courant par tenant (`select tenant_id, count(*) from tenant_subscriptions where current_marker is not null group by tenant_id having count(*) > 1` → vide), `/offres`, `/platform/subscription-plans`, `/m/abonnement`.
6. Le cron `schedule:run` existant exécute `subscriptions:process` (01h00) et `tenancy:backup-dedicated` (dimanche 03h30).

## Preuves (commandes)

- Suite : `php artisan test` (SQLite en mémoire) ; sur MariaDB : même commande avec `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=… DB_DATABASE=<base jetable> …` (les tests de DDL propres à SQLite s'y ignorent).
- Concurrence réelle : `php scripts/check-limit-concurrency.php 8 1` (voir l'en-tête du script : refuse de tourner hors base jetable « scratch »). 8 processus simultanés, limite 1 : **1** agent créé en mode réel, 8 en mode « naïf » témoin.

## Limites connues restantes

- **Fonctionnalités de service** (`assistance_prioritaire`, `options_personnalisees`) : promesses commerciales, non verrouillables dans l'application.
- **E-mails multiples** : l'anti-abus d'essais ne peut pas empêcher quelqu'un d'utiliser plusieurs vraies boîtes mail et téléphones distincts.
- **Plafond de stockage** : calculé par parcours du dossier du tenant au moment d'enregistrer un fichier (coût proportionnel au nombre de fichiers) ; les exports ponctuels téléchargés directement ne sont pas comptés.
- **Prorata** : calculé à la demande ; si la demande attend longtemps son paiement, le montant demandé reste celui du jour de la demande.
- **Passerelle** : seule la passerelle manuelle existe ; aucun prélèvement automatique tant qu'un prestataire n'est pas branché.
- **Sauvegardes dédiées** : zip des données du tenant (même format que l'export complet), conservé sur le même serveur : ce n'est pas une copie hors site.
