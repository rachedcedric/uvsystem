# Paiements d’abonnement avec Jèko Africa

## Configuration

Ajouter à l’environnement de production les variables listées dans `.env.jeko.example` :

- `APP_URL` doit être l’URL publique HTTPS de l’application ;
- `SUBSCRIPTIONS_PAYMENT_GATEWAY=jeko` active Jèko pour les renouvellements et changements d’offre initiés par les tenants ;
- `JEKO_API_KEY`, `JEKO_API_KEY_ID` et `JEKO_STORE_ID` viennent du compte Jèko Business ;
- `JEKO_WEBHOOK_SECRET` est le secret du webhook, distinct de la clé API.

Après modification :

```bash
php artisan optimize:clear
php artisan config:cache
```

Jèko est la passerelle par défaut et la seule proposée aux tenants. Le mode manuel ne sert qu'au super admin pour dépanner (par exemple un virement reçu hors Jèko) : l'action « Enregistrer un paiement » demande explicitement la passerelle `manual`. Un paiement Jèko en attente ne peut pas être confirmé à la main ; il l'est par le webhook ou la réconciliation.

Le tenant choisit explicitement son moyen de paiement avant chaque nouveau checkout : Wave, Orange Money, MTN Money, Moov Money, Djamo ou Jèko. Aucun moyen n'est présélectionné et le serveur refuse de créer une demande Jèko sans choix valide. À l'échéance d'un renouvellement automatique, l'application envoie la notification avec le montant dû, mais attend ce choix sur l'écran « Mon abonnement » avant de créer le lien de paiement.

L’application conserve et affiche les montants en FCFA entiers. L’API Jèko attend `amountCents` en centimes, multiple de 100 : le montant FCFA est donc multiplié par 100 uniquement à la frontière API. Aucun arrondi n’est appliqué au prix de l’offre ni au prorata ; le montant affiché reste identique au montant encaissé.

## Parcours dans l’application Android

Le paiement se fait **dans l’application** : « Payer » ouvre la page Jèko dans un onglet intégré à l’appli (plugin Browser de
Capacitor : bouton ✕, retour direct à l’appli), jamais dans le navigateur système. Une redirection serveur ferait sortir la
WebView de l’appli ; l’ouverture passe donc par `window.uvmasterOpenExternal` (`public/js/capacitor-bridge.js`, appelé par
le trait `OpensPaymentPage`). Hors de l’APK (navigateur classique), l’adresse s’ouvre par une navigation normale.

- À la fermeture de l’onglet, l’écran « Mon abonnement » se rafraîchit seul (événement `uvmaster-browser-finished`).
- Tant qu’un paiement Jèko est en attente, l’écran se rafraîchit toutes les 10 s (`wire:poll`) : la confirmation reçue par le
  webhook apparaît sans action de l’utilisateur. Hors connexion, Livewire suspend ce rafraîchissement.
- Les pages `/paiements/jeko/succes` et `/erreur` s’affichent dans cet onglet et invitent à le fermer pour revenir à l’appli.
- Le pont est servi par le site : ce comportement se déploie côté serveur, sans nouvel APK.

## Webhook Jèko

Dans [Jèko Business](https://business.jeko.africa/), ouvrir **Paramètres > API & Webhooks** et configurer l’unique webhook du magasin :

```text
https://votre-domaine.example/api/webhooks/jeko
```

L’endpoint :

- accepte uniquement les notifications signées avec `Jeko-Signature` ;
- calcule le HMAC-SHA256 sur le corps HTTP brut ;
- traite `Jeko-Event: TRANSACTION_COMPLETED` ;
- recoupe magasin, demande, référence, montant et devise ;
- est idempotent : une livraison répétée ne prolonge jamais deux fois l’abonnement.

Les URLs `/paiements/jeko/succes` et `/paiements/jeko/erreur` sont seulement informatives. Elles ne confirment aucun paiement.

## Réconciliation

Jèko n’émet pas de webhook pour un encaissement échoué. La commande suivante interroge donc les demandes encore en attente :

```bash
php artisan subscriptions:reconcile-jeko
```

Elle est planifiée toutes les cinq minutes dans `routes/console.php`. Le cron Laravel doit exécuter `schedule:run` chaque minute. Un succès manqué est confirmé via la même logique idempotente que le webhook ; un statut `error` marque seulement le paiement en échec.


## Expiration à 48 h

Un paiement Jèko resté « en attente » plus de **48 h** est annulé automatiquement par la commande de réconciliation (toutes les
5 minutes). La durée se règle avec `SUBSCRIPTIONS_PENDING_PAYMENT_TTL_HOURS` (48 par défaut).

- Avant d'annuler, la commande interroge Jèko. Si Jèko répond « payé », le paiement est **confirmé** (jamais annulé) ; si Jèko
  répond « erreur », il est marqué échoué ; l'annulation n'a lieu que sur un « en attente » explicite. Une panne de Jèko
  n'annule rien (un paiement peut avoir abouti).
- Le paiement passe à `failed` (motif « Expiré : aucun règlement reçu après 48 h. »), la demande de changement d'offre liée est
  annulée et le master reçoit « Paiement non abouti » : il peut de nouveau changer d'offre.
- Un paiement confirmé au même instant par le webhook n'est jamais écrasé (relecture sous verrou).
- L'écran « Mon abonnement » affiche la date limite (« valable jusqu'au … »).
- **Succès tardif** : si Jèko encaisse malgré tout un paiement déjà expiré, le webhook signé répond 409 et le serveur écrit une
  erreur **critique** dans les logs (`payment_id`, `tenant_id`, référence Jèko) : l'argent a été pris sans effet sur
  l'abonnement, à régulariser à la main (remboursement ou activation). Il n'est jamais appliqué automatiquement.
- Ne concerne que les paiements Jèko : un paiement manuel du super admin n'expire pas.

## Vérifications avant ouverture

```bash
php artisan route:list --name=webhooks.jeko
php artisan schedule:list
APP_ENV=testing APP_CONFIG_CACHE=/tmp/uvmaster-no-cache php vendor/bin/pest --compact tests/Feature/Subscriptions/JekoPaymentGatewayTest.php
```

Effectuer ensuite un paiement réel de faible montant et vérifier :

1. la redirection vers un domaine Jèko autorisé ;
2. le retour HTTP 200 du webhook dans le Dashboard Jèko ;
3. le passage du paiement à `confirmed` ;
4. la prolongation unique de l’abonnement ;
5. l’absence de clé ou de secret dans les logs.

Après 15 échecs consécutifs, Jèko désactive un webhook. Il faut alors corriger l’endpoint, puis supprimer et recréer le webhook dans le Dashboard.

Documentation officielle : [Checkout](https://developer.jeko.africa/docs/payments/checkout) et [Webhooks](https://developer.jeko.africa/docs/webhooks/integration).
