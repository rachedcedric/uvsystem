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

Migration des données existantes : les lignes des **tables cœur** sont rattachées au tenant n°1 « MODELEC CI » (`DEFAULT_TENANT_NAME` pour le nom) — c'était la seule société — et la migration s'interrompt s'il reste une ligne orpheline. Rien n'est supprimé. **Les tables héritées `clients*` ne sont jamais attribuées automatiquement** : voir « Contrôle préalable de migration ». Rollback : `php artisan migrate:rollback --step=2` (les données restent ; le retour des uniques globaux échoue volontairement s'il existe déjà des doublons entre tenants). Testé sur SQLite et sur MariaDB 12.3 avec une copie de données réelles (montée, descente, remontée) ; **non testé sur un MySQL 5.7/8 de production**.

## Contrôle préalable de migration (`tenancy:preflight`)

La migration appelle `MigrationPreflight::assertCanMigrate()` **avant toute modification**. Elle lève `MigrationBlocked` (rien n'est écrit) dans deux cas :

1. **Propriété ambiguë** : une table héritée dont le nom commence par `client` (hors cœur) contient une ligne dont le propriétaire n'est pas démontrable. Une ligne est démontrable quand l'ensemble de ses preuves désigne UN seul tenant, le tenant d'origine : colonne propriétaire, lien vers une donnée cœur (ex. `clients.agent_id → agents`), parent hérité (un solde hérite de son client), ou lignes qui la référencent (une catégorie est démontrée par les clients qui l'utilisent). Sans preuve ou avec des preuves contradictoires : **ambigu**.
2. **Sauvegarde non vérifiable** : des données existent et aucune sauvegarde de moins de 24 h (archive Spatie valide) ni sauvegarde manuelle vérifiée (< 48 h, fichier ≥ 1 Ko, ZIP valide) n'est enregistrée.

```
MIGRATION BLOQUÉE :
Impossible de déterminer avec certitude le tenant propriétaire
des données existantes.
Validation humaine requise.
```

`php artisan tenancy:preflight` (lecture seule) affiche : tenants trouvés ; lignes existantes, sans tenant, et celles **qui seront attribuées au tenant n°1** (tables cœur) ; pour chaque table `clients*` : lignes, clés étrangères, colonnes propriétaire, lignes démontrables, lignes ambiguës (avec leurs identifiants) ; état de la sauvegarde ; verdict « Migration autorisée : OUI/NON ».

Débloquer exige une action humaine explicite :
- `tenancy:preflight --backup=/chemin/dump.sql` : vérifie et enregistre la sauvegarde ;
- `tenancy:preflight --accept-unattributed --note="motif"` (+ confirmation) : l'opérateur reconnaît que les tables ambiguës **resteront sans tenant, intactes, inaccessibles à l'application**. Cette validation **n'attribue rien** ; elle est liée à l'empreinte exacte des données (toute modification l'invalide).

Aucune donnée `clients*` n'est attribuée, supprimée, archivée ni déplacée par la migration. Leur attribution éventuelle sera une décision humaine séparée, à implémenter plus tard.

Données locales testées (copie MariaDB) : `client_categories` 4 lignes (3 démontrables, **1 ambiguë : id 4**), `clients` 4, `client_soldes` 4, `client_transactions` 9 (démontrables via l'agent, `user_id` toujours vide).

## Déploiement (hébergement mutualisé)

1. **Sauvegarde de la base** (phpMyAdmin / `php artisan backup:run --only-db`) et copie de `storage/app/private`. Vérifier qu'elle s'ouvre.
2. Envoyer le code par FTP (dont `database/migrations/2026_10_07_*`, `app/`, `routes/`, `config/`, `bootstrap/`) puis `composer install --no-dev -o` si `vendor` a changé. **Ne pas encore migrer.**
3. `php artisan tenancy:preflight` : lire le rapport. Si l'hébergeur n'a pas de sauvegarde Spatie : `tenancy:preflight --backup=/chemin/dump.sql`. S'il y a des tables `clients*` ambiguës : décision humaine, puis `tenancy:preflight --accept-unattributed --note="…"`.
4. Mettre le site en maintenance (`php artisan down`), puis `php artisan migrate --force`. Vérifier : `select count(*) from agents where tenant_id is null` → 0 ; les tables `clients*` sont inchangées.
5. `php artisan db:seed --class=RolesAndPermissionsSeeder --force` pour créer le rôle `super_admin`.
6. `php artisan platform:super-admin votre@email.com --name="Votre nom"` (mot de passe demandé, 12 caractères min.). Connexion sur `/platform`.
7. `php artisan tenancy:relocate-files` (simulation) puis `--apply` : range les anciens justificatifs et exports dans `storage/app/private/tenants/{tenant d'origine}/`. Seuls des fichiers **antérieurs** au multi-tenant, et uniquement vers le tenant d'origine, sont déplacés (voir « Fichiers »).
8. Supprimer `public/storage/exports/*` et vérifier `storage/app/public/reports/` : **avant ce correctif, `uv:daily-report --save` écrivait sur le disque par défaut, qui peut être `public` (lisible par URL)** ; ces anciens PDF doivent être supprimés ou déplacés.
9. `php artisan optimize:clear` ; vérifier que le cron `schedule:run` tourne.
10. `php artisan up`. Se connecter en master du tenant 1 et contrôler agents / ventes / caisse ; créer un tenant de test dans `/platform` et vérifier qu'il démarre vide.

11. `php artisan tenancy:deploy-check` (lecture seule, aucun effet) : signale `FILESYSTEM_DISK` lisible par URL, `APP_DEBUG` actif en production, fichiers lisibles par URL sous `storage/app/public/{reports,exports,tenants}` et l'état du contrôle préalable. Code retour 1 s'il reste une erreur : à corriger avant de migrer.

L'APK n'a pas besoin d'être reconstruit : tout est côté serveur.

## Limites connues / à faire

- Sauvegardes : elles couvrent **toute** la plateforme, d'où l'accès super admin seul, désormais via `/platform` → Sauvegardes (lancer, télécharger, supprimer ; seuls les `.zip` du dossier de sauvegarde sont atteignables). Pas de restauration par tenant : restaurer une sauvegarde complète écraserait tous les tenants.
- Voir « Commandes Artisan » : chaque commande est classée GLOBALE ou PAR TENANT.
- Un `insert()` de masse via le query builder ne passe par aucun modèle : la colonne NOT NULL le fait échouer (fail closed), mais le code doit créer ses lignes via les modèles.
- Pas de contrainte composite SQL `(tenant_id, parent_id)` (décision maintenue) : la cohérence parent/enfant est garantie par le contrôle applicatif de `BelongsToTenant` (section suivante). Il ne couvre pas les écritures qui contournent les modèles (`insert()`/`update()` du query builder, `Model::where()->update()`, SQL brut) : le code ne doit pas en faire pour des tables tenantisées.
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

## Export des données d'un tenant

Le `master` d'un tenant actif télécharge toutes ses données (un CSV par table, comptes sans mot de passe ni 2FA, justificatifs, `manifest.json`) : application mobile → Rapports et exports → « Exporter toutes mes données (ZIP) », ou `/exports/mes-donnees` (limité à 3 par 10 minutes, tracé dans le journal d'activité). Le contenu est borné au tenant courant par les modèles scopés ; les cellules commençant par `=`, `+`, `-` ou `@` sont neutralisées contre l'injection de formules Excel.

## Revue responsive de l'interface mobile (/m)

Mesurée dans Chrome sur 57 écrans (écrans, formulaires et fiches) à 320, 360, 375, 390, 414, 768, 1024 et 1440 px, avec des données de démonstration : aucun débordement horizontal de la page, zones tactiles ≥ 40 px, sauf barres internes volontairement défilantes (rangées de cartes, puces, onglets).

Corrigé :
- **320 px** : deux champs date côte à côte (Rapports, Statistiques, Commissions, date/heure) faisaient déborder la page de 41 px → colonnes `minmax(0, 1fr)` (classe `.m-pair`) ; le libellé « Approvisionnements » dépassait de sa carte de 3 px → troncature.
- **≥ 600 px** (tablette, navigateur de bureau) : l'interface s'étirait sur toute la largeur ; elle reste maintenant en colonne centrée de 560 px, barre d'onglets, feuilles, bouton flottant et notifications compris.
- **Zones tactiles** : puces de filtre et de montant rapide 36/34 → 40 px ; le bandeau 2FA est entièrement cliquable (44 px).

Non vérifié ici : clavier virtuel Android, encoche/zones sûres et mode hors ligne sur appareil réel (à contrôler sur l'émulateur ou un téléphone). Les fichiers `public/build` sont ignorés par git : lancer `npm run build` et téléverser `public/build/` avec le code.

## Deuxième passe d'intrusion (`tests/Feature/TenantPenetrationTest.php`)

Un master du tenant A (toutes les permissions, donc un 403 ne masque aucune faille) attaque un tenant B rempli de vraies données. Le balayage parcourt **toutes les routes enregistrées** (API, Filament, mobile) qui prennent un identifiant de ressource et exige 403/404 ; il couvre aussi les filtres (`tenant_id`, `user_id`, `organization_id`, `agent_id`…), le mass-assignment, la falsification des propriétés Livewire (`agentId`), les écrans CRUD génériques et l'élévation vers `super_admin`. Vérifié par mutation : avec le scope désactivé, le test détecte 92 routes qui fuiraient.

Défauts trouvés et corrigés grâce à cette passe :
- **`commissions.dedup_key` unique globale** : deux sociétés n'auraient pas pu calculer chacune leur commission globale du même jour → unicité par `(tenant_id, dedup_key)` (migration 2, testée sur MariaDB).
- **`/api/transactions/{id}`** répondait 200 pour n'importe quel identifiant (paramètre de route nommé autrement que l'argument du contrôleur, donc aucune liaison au modèle) → corrigé (404 pour une ressource d'un autre tenant, lecture correcte des siennes).
- **API fournisseurs** désynchronisée du schéma (recherche sur des colonnes inexistantes → erreur 500 sur MySQL, types `bank/premium_agent/other` refusés par la base) → alignée sur `contact` et `BANQUE|AGENT_PREMIUM` (alias `bank`/`premium_agent` conservés).

Routes volontairement hors balayage : liste/création sans identifiant (`/m/g/{resource}`), téléchargements Filament `exports/imports` (liés à l'utilisateur, non utilisés), et les ressources héritées `vente-uvs` / `remboursement-agents` dont les tables n'existent plus.

## Vérification sur émulateur Android (APK debug, Pixel 1080×2400, WebView 133)

Parcours réalisé avec l'APK de débogage branché sur un serveur local (copie migrée de la base de démo) : lancement → splash natif → accueil ; barre d'état, barre d'onglets et zones sûres corrects ; jeton push enregistré côté serveur ; feuille « Que voulez-vous faire ? » ; formulaire Nouvelle vente (masque de montant « 125 000 », date/heure côte à côte, puces 40 px) ; bandeau 2FA → page d'activation autonome (QR code, clé, champ) puis retour par le bouton Retour du téléphone ; coupure puis rétablissement du réseau (bandeau orange « Hors connexion », contenu conservé, reprise sans plantage) ; aucun `FATAL EXCEPTION` / ANR dans logcat.

Corrigé suite à ce test : la page d'activation 2FA n'avait pas de lien de retour (seulement « Se déconnecter »).

Non vérifié : clavier virtuel docké (l'émulateur affichait un clavier flottant « matériel »), caméra, notification push reçue, APK de production signé, appareil physique.

## Contrôle de complétude du multi-tenant (vérifié par script et par tests)

| Contrôle | Résultat |
|---|---|
| Tables / modèles | 27 tables portent `tenant_id` ; les 26 modèles métier utilisent `BelongsToTenant`. Sans `tenant_id` : tables système uniquement (`cache`, `jobs`, `failed_jobs`, `migrations`, `roles`/`permissions`, `sessions`, `notifications` et `personal_access_tokens`, liés à l'utilisateur, `password_reset_tokens`, `tenants`) |
| Contournements du scope | Aucun `withoutGlobalScopes()` hors suppression logique ; tous les `DB::table()` métier passent par `TenantContext::table()` ; jointures bornées par une table déjà scopée ; aucune requête SQL brute nommant une table métier |
| Routes | 222 routes passées en revue : les routes sans authentification sont uniquement connexion, mot de passe oublié, santé, webhooks, page de connexion ; les téléchargements (reçus, exports, justificatifs) exigent rôle métier + tenant actif |
| Commandes | Planifiées : exécutées une fois par tenant actif ; manuelles qui envoient ou créent (`uv:send-sms`, `sms:test`, `notifications:test`) : `--tenant=ID` obligatoire |
| Calculs métier | Soldes UV, caisse, commission quotidienne du cron, analyses (et leur cache), destinataires de rappels, références : indépendants par tenant (`TenantBusinessIsolationTest`, vérifié par mutation) |
| Fuite d'infrastructure | Le diagnostic de notifications n'affiche plus le volume de la file d'attente partagée ni l'expéditeur SMS/e-mail |
| Données héritées | Les tables `clients*` (hors modèle, sans code) ne sont **jamais attribuées automatiquement** : le contrôle préalable les détecte, les compte, démontre ce qui peut l'être et **bloque** la migration si c'est ambigu (voir « Contrôle préalable de migration ») |

### Ce qui n'est PAS multi-tenant (limites assumées)
- **Infrastructure partagée** : un seul expéditeur SMS (Twilio), un seul e-mail d'envoi, un seul projet Firebase pour toutes les sociétés.
- **Réglages globaux** : fuseau, heure de clôture 18h02, seuils et textes de `config/*` sont identiques pour tous les tenants ; seuls les paliers de commission, moyens de paiement et catégories sont propres à chaque tenant.
- **Isolation applicative** : une seule base ; la garantie vient du scope Eloquent, du `NOT NULL` et des tests, pas d'une isolation matérielle (pas de row-level security, pas de contraintes composites `(tenant_id, parent_id)`).
- **Sauvegarde / restauration** : sauvegardes de toute la plateforme par le super admin ; export par tenant possible, mais pas de restauration par tenant.
- **E-mail unique à la plateforme** (identifiant de connexion) : une même adresse ne peut pas appartenir à deux tenants.
- **Absent** : facturation, quotas par offre (le champ `plan` est informatif), inscription libre de nouveaux tenants, usurpation d'identité super admin → tenant.

## Cohérence parent/enfant (V1)

`BelongsToTenant` contrôle, **avant** chaque insertion et chaque modification, que toute clé étrangère vers un modèle tenantisé désigne un parent du MÊME tenant. Les parents sont découverts par réflexion sur les relations `belongsTo` (un test vérifie qu'aucune n'est sans type de retour). En cas de parent étranger : `CrossTenantReference` (une `ValidationException`, donc erreur de champ dans Filament/Livewire, 422 en API), aucune écriture, transaction annulée, avertissement journalisé (sans donnée du parent). Les relations par clé fonctionnelle (ex. `payment_method` = code) et les parents inexistants (laissés à la clé étrangère SQL) sont ignorés. Cela couvre Filament, API, services, imports, créations et modifications. Avant ce correctif, 4 formulaires Filament acceptaient un parent étranger (approvisionnement→fournisseur, sous-distributeur→agent, charge→catégorie, solde agent→agent).

## Commandes Artisan

| Commande | Portée | Règle |
|---|---|---|
| `commissions:calculer`, `commissions:rattraper`, `commissions:recalculer`, `commissions:verifier-historique`, `recovery:process`, `uv:check-debt-alerts`, `uv:send-debt-reminders`, `uv:send-reminders`, `sms:reminders`, `reports:generate`, `uv:daily-report`, `analytics:show`, `agents:recalculate-*`, `master-solde:backfill`, `security:verify-integrity` | **PAR TENANT** | une exécution par tenant actif (`RunsPerTenant`) ; lecture/écriture SQL via `TenantContext::table()` |
| `uv:send-sms`, `sms:test`, `notifications:test`, `debts:migrate-old-system` | **UN tenant** | `--tenant=ID` obligatoire (`RunsForOneTenant`) |
| `demo:clear`, `demo:setup` | **UN tenant, développement seulement** | refusées hors `local`/`development`/`mobiledev`, si l'URL n'est pas locale, ou dès qu'il existe plusieurs tenants ; `--tenant=ID` obligatoire ; aucune option ne contourne ces refus ; `staging` est exclu |
| `backup:*`, `exports:cleanup`, `sanctum:prune-expired`, `queue:work`, `security:report`, `system:audit`, `platform:*`, `tenancy:*` | **GLOBALE** (infrastructure / plateforme) | ne produisent pas de donnée de tenant |

## Fichiers (V4, V5, V7)

- Rapports (`uv:daily-report`, `reports:generate`) : `tenants/{id}/reports/…` sur le disque **privé** ; les destinataires ne changent pas (tous les utilisateurs du tenant pour le rapport quotidien).
- Exports (Excel, `analytics:show --export`) : `tenants/{id}/exports/…`.
- `tenancy:relocate-files` : refuse tout tenant autre que le tenant d'origine ; un export hérité n'est déplacé que s'il est plus ancien que la création du multi-tenant ; un justificatif que s'il est référencé par une charge du tenant ; une destination existante n'est jamais écrasée.
- Chemin hérité `charges/…` servi uniquement au tenant d'origine (`Tenant::legacyOwnerId()`, plus de « 1 » en dur).

## Webhook e-mail (V6)

Sans utilisateur, donc sans tenant : l'adresse est cherchée hors contexte **uniquement** pour identifier le tenant propriétaire, puis la mise à jour est faite dans ce tenant. Une adresse d'agent partagée par plusieurs tenants est ambiguë : non modifiée, avertissement journalisé. Les comptes ont un e-mail unique : un seul propriétaire. Le champ `to` de Resend est une liste : elle est maintenant acceptée (auparavant : erreur de type).

## Défauts hérités découverts et corrigés pendant le durcissement

| Défaut | Effet | Correctif |
|---|---|---|
| `uv:daily-report --save` écrivait sur le **disque par défaut** (`FILESYSTEM_DISK`, parfois `public`) | PDF financiers lisibles par URL | écriture explicite sur le disque `local` (privé), dans le dossier du tenant |
| `security:verify-integrity` : `agent_soldes.*` écrasait `agents.id` | le contrôle (et `--fix`) utilisait l'id de la ligne de solde : `--fix` pouvait corriger le solde d'un autre agent ; invisible tant que les deux ids coïncident | colonnes explicites + test de non-régression |
| `security:verify-integrity` plantait dès qu'un écart existait (`agent.name`, `$agent->nom`) | le contrôle planifié de 05h00 s'arrêtait justement quand il trouvait un problème | clés corrigées |
| `analytics:show kpis` lisait une clé `uv_distribues` inexistante | commande inutilisable | ligne retirée |
| Webhook Resend : `to` est une liste, la méthode attendait une chaîne | erreur de type sur un vrai rebond | liste ou chaîne acceptées |
| `Schema::getTables()` (MySQL) liste toutes les bases visibles du serveur | un contrôle préalable aurait vu des tables d'autres bases | borné à la base de la connexion |
| API `RemboursementController` / `UvTransactionController` / `AgentResource` utilisaient d'anciennes colonnes (`nom`, `plafond_dette`, `mode_paiement`…) | `POST` remboursement en erreur 500, statistiques sur colonnes inexistantes | alignés sur le schéma actuel ; les anciennes clés JSON (`nom`, `telephone`, `plafond_dette`…) restent renvoyées pour l'APK ; `CrossTenantReference` n'est plus avalée par le `catch` générique |
| Route de webhook du paquet Resend accessible sans authentification | endpoint public non voulu | désactivée (`config/resend.php`) ; test `ApiSecurityTest` |
| Suite `tests/E2E` obsolète (jeu de données sans rôles, formes de réponse) | tests non exécutés | réécrits et ajoutés à `phpunit.xml` (16 tests) |

`agents.email_valid` (mise à jour par le webhook) n'existait dans aucune migration : créée par `2026_10_08_000000_add_email_valid_to_agents_table` (booléen, `true` par défaut, additive et rejouable ; les adresses existantes restent valides). Tant que la migration n'est pas passée, le webhook ignore la mise à jour des agents au lieu de planter.

## Tests : portée par moteur

La suite `MigrationPreflightTest` (8 tests) crée des tables : sur MySQL/MariaDB le DDL valide implicitement la transaction de test et laisserait des tables et données derrière lui ; ils ne s'exécutent donc que sur SQLite (DDL transactionnel) et sont ignorés ailleurs. Le comportement sur MariaDB est validé par la procédure manuelle (copie des données réelles : rapport, blocage, sauvegarde, validation, migration, retour arrière, remontée).
