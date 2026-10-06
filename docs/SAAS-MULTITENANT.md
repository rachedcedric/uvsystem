# MODELEC CI — Architecture SaaS multi-tenant

## Modèle

```
Plateforme (/platform)  ← SUPER_ADMIN (rôle super_admin, tenant_id NULL)
   │  crée / suspend / réactive les tenants, gère leurs comptes
   ├── Tenant 1 « MODELEC CI »  ← users (master, admin, comptable, commercial), agents, ventes, caisse…
   ├── Tenant 2 …
   └── Tenant N …
```

Base unique, colonne `tenant_id` sur chaque table métier, scope global Eloquent, fail closed.

```
Requête → authentification (session / token) → utilisateur → users.tenant_id
        → TenantContext::id()  (jamais lu dans la requête)
        → Policies / permissions Spatie
        → TenantScope : WHERE tenant_id = <courant>   (sans tenant : 1 = 0)
```

| Brique | Fichier |
|---|---|
| Contexte courant, runAs, withoutTenancy, forEachTenant, `table()`, `storagePath()`, `cacheKey()` | `app/Tenancy/TenantContext.php` |
| Scope global + assignation serveur du `tenant_id` (insert/update, résiste à `withoutEvents`) | `app/Tenancy/BelongsToTenant.php`, `TenantScope.php`, `UserTenantScope.php` |
| Création d'un tenant (données de référence + 1er admin) | `app/Tenancy/TenantProvisioner.php` |
| Console super admin | `app/Providers/Filament/PlatformPanelProvider.php`, `app/Filament/Platform/` |
| Cron par tenant | `app/Console/Commands/Concerns/RunsPerTenant.php` |

Règles : `tenant_id` n'est dans aucun `$fillable` ; une écriture avec un autre tenant que le courant lève une exception ; `tenant_id` est immuable ; les `DB::table()` sur tables métier passent par `TenantContext::table()` ; les clés de cache de chiffres sont préfixées par le tenant ; fichiers dans `storage/app/private/tenants/{id}/` ; l'unicité (téléphone agent, code moyen de paiement, catégories…) est **par tenant** ; l'e-mail utilisateur et les références restent uniques globalement.

## Rôles et permissions

| Rôle | Portée | Accès |
|---|---|---|
| `super_admin` | Plateforme, `tenant_id` NULL | `/platform` uniquement (jamais `/admin`, `/m`, API ni données métier). Seul à pouvoir télécharger les sauvegardes |
| `master` (= TENANT_ADMIN) | Son tenant | Toutes les permissions métier |
| `admin` (= MANAGER) | Son tenant | Tout sauf gestion des rôles |
| `comptable`, `commercial` (= USER) | Son tenant | Permissions du seeder `RolesAndPermissionsSeeder` |

Les permissions Spatie existantes (`view_agents`, `create_uv_transactions`, …) sont conservées et toujours évaluées dans le tenant de l'utilisateur. `super_admin` n'a aucune permission métier. Un compte rattaché à un tenant qui recevrait le rôle `super_admin` n'obtient aucun pouvoir (`isSuperAdmin()` exige `tenant_id` NULL) ; les écrans de rôles n'offrent jamais `super_admin`.

Tenant suspendu : `User::canAccessPanel()` refuse → blocage immédiat du web (`/admin`), du mobile (`/m`, y compris les actions Livewire) et de l'API (token inclus), sans suppression de données.

## Tables modifiées

| Table | Changement | Raison | Migration |
|---|---|---|---|
| `tenants` | créée (name, slug, status, plan, contact, trial_ends_at, suspended_at) | entité tenant | `2026_10_07_000001` |
| 25 tables métier (agents, agent_soldes, uv_transactions, remboursements, commissions, paliers_commission, commission_recalculations, sous_distributeur_*, agent_partenaires, dettes_agents, ventes_uv, remboursements_agents, fournisseurs, approvisionnements, remboursements_fournisseurs, master_soldes, caisse_mouvements, caisse_categories, charges, charge_categories, payment_methods, push_tokens) | `tenant_id` FK → NOT NULL | propriété de la donnée | 000001 (ajout + rattachement) puis 000002 (NOT NULL) |
| `users`, `activity_log` | `tenant_id` nullable | super admin / logs de plateforme sans tenant | 000001 |
| agents.phone, sous_distributeurs.telephone, payment_methods.code, caisse_categories.normalized_name, charge_categories.code | unique global → unique `(tenant_id, colonne)` | deux sociétés peuvent partager ces valeurs | 000002 |
| uv_transactions, remboursements, approvisionnements, caisse_mouvements, charges, commissions, agents, activity_log | index `(tenant_id, created_at)` / `(tenant_id, status)` | performance par tenant | 000001 |

Migration des données existantes : toutes les lignes sont rattachées au tenant n°1 « MODELEC CI » (`DEFAULT_TENANT_NAME` pour le nom), la migration vérifie qu'aucune ligne n'est orpheline et s'interrompt sinon. Rien n'est supprimé. Rollback : `php artisan migrate:rollback --step=2` (les données restent ; le retour des uniques globaux échoue volontairement s'il existe déjà des doublons entre tenants). Testé sur SQLite avec données (montée, descente, remontée) ; **non testé sur MySQL**.

## Déploiement (hébergement mutualisé)

1. **Sauvegarde de la base** (phpMyAdmin / `php artisan backup:run --only-db`) et copie du dossier `storage/app/private`.
2. Mettre le site en maintenance si possible (`php artisan down`).
3. Envoyer le code par FTP (dont `database/migrations/2026_10_07_*`, `app/`, `routes/`, `config/`, `bootstrap/`) puis `composer install --no-dev -o` si `vendor` a changé.
4. `php artisan migrate --force` (2 migrations). Vérifier : `select count(*) from agents where tenant_id is null` → 0.
5. `php artisan db:seed --class=RolesAndPermissionsSeeder --force` pour créer le rôle `super_admin`.
6. `php artisan platform:super-admin votre@email.com --name="Votre nom"` (mot de passe demandé, 12 caractères min.). Connexion sur `/platform`.
7. `php artisan tenancy:relocate-files` (simulation) puis `php artisan tenancy:relocate-files --apply` : range anciens justificatifs et exports dans `storage/app/private/tenants/1/`.
8. Supprimer `public/storage/exports/*` (anciens exports publics).
9. `php artisan optimize:clear` ; vérifier que le cron `schedule:run` tourne (les commandes planifiées s'exécutent désormais une fois par tenant actif).
10. `php artisan up`. Se connecter en master du tenant 1 et contrôler agents / ventes / caisse ; créer un tenant de test dans `/platform` et vérifier qu'il démarre vide.

L'APK n'a pas besoin d'être reconstruit : tout est côté serveur.

## Limites connues / à faire

- Sauvegardes : elles couvrent **toute** la plateforme, d'où l'accès super admin seul ; pas encore d'écran de sauvegarde dans `/platform` (téléchargement via `/backup/download/…` ou cPanel). Une sauvegarde/export par tenant reste à construire.
- Les 15 commandes planifiées/maintenance utilisent `RunsPerTenant` ; les commandes de démo (`SetupDemoData`, `ClearDemoData`…) restent hors tenant et sont à ne pas lancer en production.
- Un `insert()` de masse via le query builder ne passe par aucun modèle : la colonne NOT NULL le fait échouer (fail closed), mais le code doit créer ses lignes via les modèles.
- Contraintes composites `(tenant_id, parent_id)` entre tables non posées : la cohérence parent/enfant repose sur le scope et sur les règles `exists` tenant-aware.
- Pas d'impersonation (super admin → tenant) volontairement.

## Double authentification (2FA)

| Population | Règle |
|---|---|
| Tout utilisateur qui a activé la 2FA | Code exigé à chaque nouvelle session sur `/admin`, `/agent`, `/platform`, `/m` (y compris actions Livewire) et à la connexion API (`otp`). Option « faire confiance à cet appareil » 30 jours (liée au secret : réinitialiser la 2FA invalide les appareils) |
| `master`, `admin` | Bandeau de rappel tant que `SECURITY_2FA_ENFORCE_FROM` est vide ou dans le futur ; **obligatoire** (redirection vers l'activation) à partir de cette date |
| `super_admin` | Toujours obligatoire, sans délai |
| `comptable`, `commercial` | Facultative |

Garde-fous : codes jamais rejouables, 5 essais / 5 min par compte et IP, codes de récupération à usage unique, désactivation = mot de passe requis et refusée si le rôle impose la 2FA, page d'activation autonome `/security/2fa` toujours accessible (aucun verrouillage sans issue), `SECURITY_2FA_ENABLED=false` coupe tout en urgence.

**Déploiement progressif recommandé** : 1) déployer sans `SECURITY_2FA_ENFORCE_FROM` (rappel seulement) ; 2) quand les `master`/`admin` ont activé leur 2FA, fixer par exemple `SECURITY_2FA_ENFORCE_FROM=2026-11-15` dans `.env` puis `php artisan config:clear` ; 3) en cas de téléphone perdu : le super admin utilise « Réinitialiser la 2FA » sur l'utilisateur dans `/platform`, ou `php artisan platform:2fa-reset email@…` (le seul moyen pour le super admin lui-même).

Le premier super admin doit activer sa 2FA dès sa première connexion à `/platform` (redirection automatique).
