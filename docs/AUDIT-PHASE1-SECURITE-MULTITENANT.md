# MODELEC CI — Audit Phase 1 (sécurité, isolation, mobile)

Date : 2026-10-06 · Périmètre : dépôt complet · **Aucun fichier de code modifié par cet audit.**

## 0. Synthèse

L'application est aujourd'hui **mono-entreprise** (une seule société, un compte "master", des soldes globaux). Il n'existe **aucune notion de tenant** : aucune table n'a de `tenant_id`, aucun scope global, aucun contexte tenant. La conversion en multi-tenant est donc une vraie refonte du modèle de données et des services financiers, pas un simple ajout de colonne.

Indépendamment du multi-tenant, l'audit révèle **des failles exploitables dès maintenant en production** (section 6). Les trois plus graves :

1. `POST /api/auth/register` est **public** : n'importe qui crée un compte et obtient un token Sanctum valide.
2. Plusieurs endpoints API n'ont **aucune autorisation** (Dashboard, Reports, ActivityLog, Fournisseurs en CRUD complet). Un compte fraîchement auto-enregistré, sans rôle, peut donc lire toutes les données financières et créer/modifier/supprimer des fournisseurs.
3. `GET /remboursement/{id}/receipt` : **IDOR** — tout utilisateur connecté lit n'importe quel reçu par ID séquentiel, sans permission.

## 1. Stack (points 1 à 8 de la demande)

| Élément | Constat |
|---|---|
| Backend | Laravel 12, PHP ≥ 8.2 |
| Admin web | Filament 3.2 sur `/master` (panels `admin` + dossier `Filament/Agent`) |
| Mobile | Capacitor 8 (WebView sur `https://uvsystem.pro/m`), UI Blade + Livewire (`routes/mobile.php`, `app/Livewire/Mobile/*`) ; pas de Laravel dans l'APK |
| API REST | `routes/api.php`, Sanctum (`auth:sanctum`), 11 contrôleurs ; **non utilisée par l'APK** (qui passe par sessions web + Livewire) |
| BDD | MySQL en prod, SQLite en test/dev ; ORM Eloquent ; ~40 migrations |
| Auth | Sessions (web/Filament/mobile, `remember` forcé) + tokens Sanctum (API). 2FA TOTP (pragmarx/google2fa) : middleware `Require2FA` **commenté** dans le panel, non appliqué |
| Sessions/tokens | Session DB 120 min (`config/security.php` annonce 30 min : **non appliqué**). Tokens Sanctum **sans expiration** (`expiration => null`), "refresh" = simple recréation |
| Rôles | Spatie permission : `master`, `admin`, `comptable`, `commercial` ; permissions plates (`view_agents`, `create_agents`, …) via `RolesAndPermissionsSeeder` |
| Autres | spatie/activitylog, spatie/laravel-backup, DomPDF, Maatwebsite Excel, Firebase push (FCM), Twilio SMS, Resend/Mailtrap |

## 2. Modèles / tables et appartenance des données (points 11-12)

Toutes les tables ci-dessous contiennent des données d'une organisation et devront porter `tenant_id`.

| Domaine | Tables / modèles | Remarque multi-tenant |
|---|---|---|
| Utilisateurs | `users`, rôles/permissions Spatie, `personal_access_tokens`, `push_tokens`, `sessions`, `notifications` | `users.tenant_id` ; rôles Spatie à passer en mode *teams* |
| Distribution UV | `agents`, `agent_soldes`, `uv_transactions`, `remboursements`, `commissions`, `paliers_commission`, `commission_recalculations` | Chaîne agent → transactions → remboursements → commissions |
| Sous-distribution | `sous_distributeurs`, `sous_distributeur_soldes`, `_transactions`, `_remboursements` | Dépend de `agents` |
| Partenaires (legacy) | `agent_partenaires`, `dettes_agents`, `ventes_uv`, `remboursements_agents` | Tables partielles (voir mémoire projet) |
| Approvisionnement | `fournisseurs`, `approvisionnements`, `remboursements_fournisseurs` | |
| **Singleton** | **`master_soldes`** (un enregistrement par master, `MasterSoldeService` verrouille **toutes** les lignes : `MasterSolde::query()->lockForUpdate()`) | **Point dur** : le solde UV global doit devenir un solde par tenant ; le verrou global sérialiserait tous les tenants |
| Trésorerie | `caisse_mouvements`, `caisse_categories`, `charges`, `charge_categories`, `payment_methods` | Catalogues (catégories, moyens de paiement) à dupliquer par tenant |
| Audit | `activity_log` (Spatie) | Pas de `tenant_id` : tout lecteur de l'API voit les logs de tous |
| Fichiers | `storage/app/private` (justificatifs, backups), `storage/app/public/exports` | Pas de séparation par organisation |

Relations notables : `User 1-1 Agent`, `Agent 1-N UvTransaction/Remboursement/SousDistributeur`, `Agent 1-1 AgentSolde`, `Fournisseur 1-N Approvisionnement/RemboursementFournisseur`, `Commission` auto-référencée (`commission_globale_id`), `Charge → ChargeCategorie`, `CaisseMouvement → CaisseCategorie`, `PaymentMethod.code ← Remboursement.payment_method` (clé fonctionnelle, pas un FK).

## 3. Surfaces d'accès (points 9, 10, 13-15, 17)

- **API** (`routes/api.php`) : 40+ routes, détail en annexe A.
- **Web** (`routes/web.php`) : 2FA, `exports/download/{filename}`, `backup/download/{path}`, `charges/justificatif/{charge}/download`, `remboursement/{id}/receipt[/download]`.
- **Mobile `/m`** : ~60 routes Livewire derrière `mobile.access` (connecté + `canAccessPanel`). Un moteur CRUD générique (`app/Support/Mobile/Crud`) accepte `{resource}/{id}` depuis l'URL et appelle `findOrFail($id)` puis `MobileResource::can()` (permission Spatie, **pas de contrôle de propriété**).
- **Filament** : ~19 Resources, ~17 pages, ~24 widgets. Les Resources utilisent les Policies ; les pages/widgets personnalisés doivent vérifier eux-mêmes (déjà corrigé ponctuellement pour les justificatifs).
- **IDs acceptés depuis le client** : `agent_id`, `fournisseur_id` (API et Livewire), `{agent}`, `{charge}`, `{remboursement}`, `{transaction}`, `{commission}` (route model binding sans scope), `subject_id`/`causer_id` (ActivityLog).
- **SQL brut** : 118 occurrences (`selectRaw`, `whereRaw`, `DB::raw`…), à auditer une par une lors de l'introduction du scope tenant (un scope global Eloquent ne protège pas le SQL brut ni `DB::table()`).

## 4. Autorisation actuelle

Aujourd'hui : **authentification** (session/token) → **permission plate Spatie** (`$user->can('view_agents')`). Il n'y a ni couche d'isolation, ni vérification de propriété : l'`id` suffit. C'est cohérent en mono-entreprise (tous les utilisateurs voient tout), mais incompatible avec le multi-tenant.

Contrôles d'autorisation par contrôleur API : Agent 6, UvTransaction 3, Remboursement 3 (mais pas toutes les méthodes), **Dashboard 0, Report 0, ActivityLog 0, Fournisseur 0**.

## 5. Mobile (point 18)

Déjà en place : Capacitor + WebView, splash, bridge natif (téléchargements, retour, caméra, liens externes), navigation à onglets, push FCM, bandeau hors-ligne, version 4.0. Reste à vérifier à l'étape responsive : 320/360/375/390/414 px sur les ~86 écrans, tableaux denses, clavier mobile, états loading/erreur. Stockage des tokens : l'APK n'en détient pas (cookie de session dans le WebView) ; un stockage natif sécurisé n'est nécessaire que si on bascule vers l'API token (voir décision D3).

## 6. Vulnérabilités trouvées

| # | Sévérité | Lieu | Risque | Correctif proposé |
|---|---|---|---|---|
| V1 | 🔴 Critique | `routes/api.php` `POST /auth/register` + `AuthController::register` | Création de compte ouverte à tout internet → token valide | Supprimer la route (ou invitation uniquement par un admin du tenant) |
| V2 | 🔴 Critique | `DashboardController`, `ReportController`, `ActivityLogController`, `FournisseurController` | Aucune autorisation : tout compte authentifié lit KPI, dettes des agents, exports, journal d'audit complet ; CRUD complet des fournisseurs (y compris suppression) | `authorize()`/permissions + scope tenant |
| V3 | 🔴 Critique | `RemboursementReceiptController` | IDOR : reçu de n'importe quel remboursement par ID séquentiel, sans permission | Policy `view` + scope tenant ; référence non devinable |
| V4 | 🟠 Élevé | `ExportDownloadController` / `storage/app/public/exports` | Aucun contrôle de permission ni de propriétaire ; fichiers sur le disque *public* (accessibles directement par URL si le lien `storage` existe) | Disque privé + nom aléatoire + vérification du propriétaire |
| V5 | 🟠 Élevé | `config/sanctum.php` | Tokens sans expiration ; "refresh" sans notion de famille/révocation | `expiration`, abilities, révocation par appareil |
| V6 | 🟠 Élevé | Filament `AdminPanelProvider` | 2FA non appliquée (middleware commenté) alors que `config/security.php` la déclare requise pour master/admin | Réactiver pour les rôles concernés |
| V7 | 🟠 Élevé | Mobile `Crud\*`, routes `{agent}`, `{charge}`, `{transaction}`… | Route-model-binding / `findOrFail($id)` sans scope : l'isolation entre tenants serait **inexistante** dès qu'une 2ᵉ organisation existe | Scope global tenant (voir §7) |
| V8 | 🟡 Moyen | `FournisseurController::index` `orderBy($sortBy…)` | Tri sur colonne arbitraire fournie par le client (pas d'injection grâce au grammar, mais énumération de colonnes/erreurs SQL) | Liste blanche de colonnes |
| V9 | 🟡 Moyen | `WebhookController` | Logge `headers->all()` et le payload complet (données sensibles/secrets potentiels) ; vérification de signature à confirmer côté Mailtrap | Redaction + signature obligatoire |
| V10 | 🟡 Moyen | `config/cors.php` | `supports_credentials` avec origines figées + motif `192.168.*` conservé en prod ; chemin `admin/*` exposé | Origines par environnement |
| V11 | 🟡 Moyen | `config/security.php` | Valeurs de sécurité (session 30 min, idle 15 min, rotation) **déclarées mais non branchées** : fausse assurance | Soit les appliquer, soit les supprimer |
| V12 | 🟡 Moyen | `AuthController::user` / `login` | Retourne l'objet `User` complet (+ relation agent) ; `register`/`login` sans verrouillage de compte distinct du throttle IP | `UserResource` filtré, throttle par e-mail |
| V13 | 🟢 Faible | `GET /api/rate-limits`, `/api/health` | Publics, divulguent la configuration | Retirer `rate-limits` |
| V14 | 🟢 Faible | `$fillable` du `User` inclut `two_factor_*` | Mass-assignment sensible si un jour le modèle reçoit `$request->all()` | Retirer du `$fillable` |

Déjà corrigé avant cet audit (mémoire projet) : `BackupController` (lecture de fichiers arbitraires), notifications mobiles, ping hors-ligne sans session.

Limite honnête de cet audit : je n'ai pas encore lu en détail les 118 requêtes SQL brutes, les widgets Filament, les 40 Policies/pages restantes ni les 119 contrôles d'autorisation Livewire ; ils seront traités dans la phase d'implémentation, table par table.

### État des correctifs (2026-10-06, tests : `tests/Feature/ApiSecurityTest.php`, 11 tests)

| # | État | Ce qui a été fait |
|---|---|---|
| V1 | ✅ corrigé | Route et méthode `register` supprimées ; `login` refuse les comptes sans rôle métier |
| V2 | ✅ corrigé | Middleware `staff` (rôle métier obligatoire) sur tout le groupe protégé ; `can:view_dashboard/view_reports/view_activity_log` ; `authorize()` sur toutes les méthodes Fournisseur, Agent (sous-routes), UvTransaction (update, stats), Remboursement (update, stats) |
| V3 | ✅ corrigé | Policy `view` sur les reçus ; **bug supplémentaire corrigé** : relation `Remboursement::paymentMethod` manquante (le reçu plantait en 500 pour tout le monde) |
| V4 | ✅ corrigé | Exports sur le disque privé `local`, permission d'export requise, basename forcé. **Action manuelle** : supprimer `public/storage/exports/*` en production (anciens fichiers encore accessibles par URL) |
| V5 | ✅ corrigé | Expiration Sanctum 7 jours (`SANCTUM_TOKEN_EXPIRATION`), purge quotidienne planifiée |
| V6 | ⏸ non fait | Réactiver `Require2FA` peut verrouiller les master/admin qui n'ont pas configuré leur 2FA : à décider (voir questions) |
| V7 | ⏳ phase multi-tenant | Scope global tenant |
| V8 | ✅ corrigé | Tri en liste blanche, `per_page` borné à 100 |
| V9 | ✅ corrigé | En-têtes de webhook filtrés dans les logs (le payload reste loggé) |
| V10 | ✅ corrigé | CORS : origines via `CORS_ALLOWED_ORIGINS`, motifs réseau local seulement en `APP_ENV=local`, `admin/*` retiré |
| V11 | ⏸ non fait | `config/security.php` : valeurs non branchées, à brancher ou supprimer |
| V12 | ✅ corrigé | Payload utilisateur limité à id/name/email/roles/2fa |
| V13 | ✅ corrigé | `/api/rate-limits` supprimé |
| V14 | ❌ abandonné | `two_factor_*` doivent rester dans `$fillable` : `TwoFactorAuthService` les écrit par `update()`. Aucun chemin n'envoie `$request->all()` au modèle |

Bugs hérités découverts en chemin (non corrigés) : validation API `Fournisseur.type` (`bank|premium_agent|other`) incompatible avec l'enum BDD (`AGENT_PREMIUM|BANQUE`) → création par l'API échoue ; un invité sur une route web protégée provoquait une erreur 500 (`Route [login] not defined`), maintenant redirigé vers `/master/login`.

## 7. Architecture cible proposée

**Modèle : base de données unique, colonne `tenant_id`, scope global Eloquent** (pas une base par tenant : l'hébergement mutualisé cPanel/FTP et le déploiement actuel ne le permettent pas raisonnablement).

```
Requête authentifiée
  → middleware ResolveTenant        (tenant = $request->user()->tenant_id, jamais un paramètre client)
  → TenantContext (singleton)       currentTenant() / TenantContext::id()
  → trait BelongsToTenant           scope global + remplissage auto de tenant_id à la création
  → Policies / permissions Spatie   (mode "teams", team = tenant)
  → Requête SQL                     WHERE tenant_id = :courant  (fail closed si pas de contexte)
```

Principes :
- `tenant_id` posé **uniquement côté serveur** (`creating` hook) ; absent de tous les `$fillable` ; ignoré s'il vient du client.
- Sans contexte tenant, les requêtes retournent **zéro ligne** (fail closed) ; seuls des `withoutTenancy()` explicites (commandes cron, super-admin) contournent le scope.
- Route-model-binding scopé : un ID d'un autre tenant donne **404** (pas de fuite d'existence).
- Contraintes composites `(tenant_id, id)` + index `(tenant_id, created_at)` / `(tenant_id, status)` ; FK composites là où la chaîne relationnelle l'exige.
- `master_soldes` devient un solde **par tenant** ; le verrou `lockForUpdate` est restreint au tenant.
- Fichiers : `storage/app/private/tenants/{tenant_id}/…`, servis uniquement par contrôleur autorisé.
- Journal d'audit enrichi (`tenant_id`, `user_id`, action, ressource, IP, résultat) sans données sensibles.
- Rôles : `SUPER_ADMIN` (plateforme, hors tenant) / `TENANT_ADMIN` (=master) / `MANAGER` (=admin) / `USER`(comptable, commercial) / `READ_ONLY`, en conservant les permissions Spatie existantes pour la compatibilité.

Migration des données existantes : créer le tenant n°1 "MODELEC CI", y rattacher **toutes** les lignes existantes dans l'ordre users → agents → chaînes dépendantes, puis ajouter `NOT NULL` + FK + index, avec rollback par migration réversible et sauvegarde préalable (`spatie/laravel-backup` déjà présent). Aucune suppression.

## 8. Décisions à confirmer avant de coder

- **D1 — Périmètre.** (a) Multi-tenant complet (recommandé si vous avez réellement un 2ᵉ client) ; (b) d'abord uniquement les correctifs de sécurité V1-V14, multi-tenant ensuite.
- **D2 — Qu'est-ce qu'un tenant ?** Une société cliente qui exploite sa propre distribution UV (ses agents, fournisseurs, caisse, soldes) — c'est mon hypothèse.
- **D3 — API Sanctum.** L'APK ne l'utilise pas. Recommandation : la **conserver mais la verrouiller** (pas d'inscription publique, tokens expirants, scope tenant), sans migrer l'APK vers des tokens.
- **D4 — Déploiement.** Hébergement mutualisé : migrations lancées via SSH/commande, ou à travers un script artisan que je fournis ; une sauvegarde BDD avant toute migration est obligatoire.

## Annexe A — Matrice des routes API

| Méthode | Route | Auth | Tenant (cible) | Permission aujourd'hui | Propriété vérifiée |
|---|---|---|---|---|---|
| POST | /api/auth/register | **Non** | — | — | — **V1** |
| POST | /api/auth/login | Non (throttle:auth) | — | — | — |
| GET | /api/health, /api/rate-limits | Non | — | — | — |
| GET | /api/auth/user, POST logout, logout-all, refresh | Oui | — | — | token courant |
| GET | /api/dashboard, /realtime, /charts, /top-agents, /alerts | Oui | **requis** | **aucune** | **non** — V2 |
| GET/POST/PUT/DELETE | /api/agents[/{id}] | Oui | requis | Policy Agent | non (global) |
| GET | /api/agents/{agent}/situation, /transactions, /remboursements | Oui | requis | Policy `view` | non (global) |
| GET/POST/PUT | /api/transactions[/{id}] | Oui | requis | Policy (3 méthodes sur 4 ?) | non |
| GET | /api/transactions/stats/daily, /monthly | Oui | requis | **aucune** | non |
| GET/POST/PUT | /api/remboursements[/{id}], /remboursements-stats | Oui | requis | Policy partielle | non |
| GET/POST/PUT/DELETE | /api/fournisseurs[/{id}], /list, /{id}/transactions, /{id}/stats | Oui | requis | **aucune** | **non** — V2 |
| GET | /api/reports, /agents, /transactions, /export | Oui | requis | **aucune** | **non** — V2 |
| GET | /api/activities, /stats, /log-names, /events, /{id} | Oui | requis | **aucune** | **non** — V2 |
| GET/POST/DELETE | /api/notifications/* | Oui | via user | — | **oui** (par utilisateur) |
| * | /api/2fa/* | Oui | via user | — | oui |
| POST | /api/webhooks/resend, /mailtrap | Signature | — | — | — |

Routes web : `exports/download/{filename}` (auth seule — V4), `backup/download/{path}` (master/admin, .zip), `charges/justificatif/{charge}/download` (permission `view_charges`, sans scope), `remboursement/{id}/receipt[/download]` (auth seule — **V3**).
