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

- **Limites** (`User`, `Agent`) : création refusée (`SubscriptionLimitReached`, 422 en API) ; la restauration d'un agent supprimé est recomptée. Rétrograder ne supprime rien : les dépassements existants restent, seule la création est bloquée.
- **Fonctionnalités** : `import_donnees` (import Filament et `/m`) et `scoring_agents` (scoring Filament et `/m`) sont refusées (403) si l'offre ne les inclut pas. Les autres identifiants sont affichés mais pas encore appliqués (voir risques).

## Politique d'expiration

| État | Niveau | Effet |
|---|---|---|
| Tenant suspendu par la plateforme | Bloqué | accès coupé (sessions et jetons révoqués), comme avant |
| Essai/abonnement actif non échu ; annulé mais période payée en cours | Plein | tout |
| Expiré, annulé échu, suspendu (abonnement) | **Restreint** | lecture, exports, page d'abonnement ; **aucune écriture métier** ; création d'utilisateurs/agents refusée |
| Aucune ligne d'abonnement | Plein (hérité) | sauf `SUBSCRIPTIONS_REQUIRED=true` |

Rien n'est supprimé à l'expiration (données, utilisateurs, agents, historiques). Le statut est dérivé des **dates** : l'accès ne dépend jamais du passage du cron. Les écritures du code serveur de confiance (`runAs`, commandes) ne sont pas bridées, **sauf** la création d'utilisateurs et d'agents. Exemptés du blocage d'écriture : comptes (2FA, mot de passe), journal d'activité, jetons push, soldes dérivés.

Changement par rapport à avant : `Tenant::isActive()` ne dépend plus de `trial_ends_at` ; un essai expiré ne coupe plus l'accès, il restreint les écritures.

## Paiements

Aucun prestataire n'est installé. Un paiement n'est `confirmed` que sur une **confirmation réelle** : mode manuel = attestation d'un `isSuperAdmin()` avec **référence obligatoire**. `SubscriptionBilling::confirm` est idempotent (un paiement ne prolonge qu'une fois) ; un échec ne modifie pas l'abonnement. Ajouter CinetPay/PayDunya plus tard = une classe implémentant `SubscriptionPaymentGateway` déclarée dans `config/subscriptions.php` (`gateways`).

## Expiration planifiée

`php artisan subscriptions:process` (planifiée tous les jours à 01h00 dans `routes/console.php`, avec `withoutOverlapping` et `onOneServer`) : expire les essais/abonnements échus, avertit à J-7, J-3, J-1. Idempotente : l'expiration n'agit qu'une fois et chaque avertissement a une clé unique (abonnement + échéance + palier). Une erreur sur un tenant est journalisée et n'arrête pas les autres ; code de sortie 1 s'il y en a eu. Notifications : `app/Notifications/Subscriptions/` (mail + base), envoyées aux masters ; un échec d'envoi est journalisé, n'altère jamais l'état et lève la réservation anti-doublon.

## Interfaces

- `/platform` : **Offres** (`SubscriptionPlanResource`), **Abonnements** (`TenantSubscriptionResource` : attribuer, changer d'offre, prolonger l'essai, enregistrer un paiement, suspendre, réactiver, annuler, historique, filtres statut/offre/cycle/échéance/tenant), tableau de bord (tenants, essais, actifs, expirés, suspendus, MRR/ARR estimés, répartition, expirations à 7 jours). Accès par `User::isSuperAdmin()` (rôle **et** aucun tenant) à chaque niveau : `canAccess`, policies, action revérifiée dans le handler.
- **MRR/ARR** : revenu récurrent **estimé**, pas encaissé. Mensuel : MRR = prix, ARR = prix × 12. Annuel : MRR = prix / 12, ARR = prix. Seuls les abonnements actifs comptent, au prix figé à la souscription ; les abonnements hérités (prix 0) ne pèsent pas. Calcul en mémoire (centaines de tenants).
- `/offres` (public) : offres actives uniquement, bascule Mensuel/Annuel, prix et limites lus en base.
- `/souscrire` (public) : le serveur recalcule offre, prix, limites, essai, rôle (toujours `master`) ; jeton à usage unique anti double envoi ; refus des doublons d'e-mail et de nom ; mot de passe 12 caractères (maj., min., chiffres) ; conditions obligatoires ; limitée à 5 envois par heure et par IP ; un compte déjà rattaché à une entreprise est refusé ; un essai n'est pas rejoué pour un même e-mail.
- `/m/abonnement` (master/admin du tenant courant) : offre, statut, cycle, prix, échéance, jours restants, essai, utilisation, fonctionnalités, avertissements. Accessible aussi en accès restreint.

## Migration `2026_10_09_000000_create_subscription_tables`

- **Ajoute** 4 tables et les 3 offres ; **crée un abonnement par tenant existant** d'après `tenants.plan` (insensible à la casse ; inconnu ou vide : `SUBSCRIPTIONS_LEGACY_PLAN`, par défaut `entreprise` = illimité). Essai futur : `trialing` ; essai passé : `expired` ; sinon `active` sans échéance ; prix appliqué 0 (donc pas de MRR) ; `metadata.legacy = true`.
- **Ne supprime et ne modifie rien** : `tenants.plan` et `tenants.trial_ends_at` restent (champs **obsolètes**, recopiés en miroir par le manager, jamais lus). Leur suppression fera l'objet d'une migration distincte après validation complète.
- Passe **après** la migration multi-tenant ; sur une base de production elle ne s'exécute qu'après le contrôle préalable `tenancy:preflight` (la migration multi-tenant reste bloquée tant que celui-ci n'est pas validé).
- **Retour arrière** : `php artisan migrate:rollback --step=1` supprime uniquement les 4 nouvelles tables (l'historique d'abonnements est perdu) ; rien d'autre n'est touché.

## Déploiement (à NE PAS exécuter sans validation explicite)

1. Sauvegarde vérifiée de la base et de `storage/app/private`.
2. Étapes de `SAAS-MULTITENANT.md` (contrôle préalable, migration multi-tenant) si elles n'ont pas déjà été faites.
3. Envoyer le code, `composer install --no-dev -o`, `npm run build` (ou envoyer `public/build`).
4. `php artisan down` puis `php artisan migrate --force` puis `php artisan optimize:clear` puis `php artisan up`.
5. Vérifier : un abonnement courant par tenant (`select tenant_id, count(*) from tenant_subscriptions where current_marker is not null group by tenant_id having count(*) > 1` → vide), `/offres`, `/platform/subscription-plans`, `/m/abonnement`.
6. Le cron `schedule:run` existant exécute `subscriptions:process`.

## Risques et limites connus

- **Limites non atomiques** : le contrôle « compter puis insérer » n'est pas verrouillé ; deux créations strictement simultanées peuvent dépasser la limite d'une unité.
- **Notifications en file** : un échec survenant plus tard dans le worker n'apparaît que dans les jobs échoués.
- **Fonctionnalités** : seules `import_donnees` et `scoring_agents` sont appliquées ; `storage_limit_mb` est enregistré mais pas appliqué.
- **Souscription publique** : pas de vérification d'e-mail ; un essai peut être rejoué avec une autre adresse.
- **Tenant sans abonnement** : illimité (compatibilité) tant que `SUBSCRIPTIONS_REQUIRED` n'est pas activé.
- **Changement d'offre côté tenant** : pas de libre-service (le master voit les offres, la plateforme applique le changement) ; pas de proratisation.
- `auto_renew` est enregistré mais sans effet avec la passerelle manuelle.
