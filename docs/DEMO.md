# Instance de démonstration — DÉVELOPPEMENT UNIQUEMENT

> **Ne jamais utiliser ces comptes, ces mots de passe ni ces réglages en production.**
> Ce document décrit une instance jetable montée pour valider le travail (audit du 2026-10-08). Elle n'est pas
> installée par le projet : aucun seeder, aucune migration ni aucune commande du dépôt ne crée ces comptes.

## Ce qui existe

| Élément | Valeur |
|---|---|
| Où | Sur le poste de développement, hors du dépôt (dossier temporaire de la session, base SQLite dédiée) |
| Adresse | `http://127.0.0.1:8770` (serveur PHP intégré, accessible seulement depuis le poste) |
| Super admin (plateforme, `/platform`) | `superadmin@demo.test` |
| Master (espace entreprise `/master` et mobile `/m`) | `master@demo.test` |
| Lecture seule (même entreprise) | `lecture@demo.test` (rôle `lecture_seule`, ajouté le 2026-10-08 après avoir rejoué le seeder de rôles) |
| Entreprise | « Boutique Soleil », offre Pro |
| Données | 3 agents fictifs avec quelques ventes ; l'abonnement est volontairement placé à J-3 pour montrer le bandeau d'échéance |
| Mots de passe | **Non écrits ici** (transmis hors dépôt, à usage unique). Pour en choisir un autre : voir « Recréer » |

Particularités, valables **uniquement** pour le processus de démonstration :

- `APP_ENV=local`, base et stockage séparés (`DB_DATABASE`, `LARAVEL_STORAGE_PATH`), jamais ceux du `.env` du projet ;
- `SECURITY_2FA_ENABLED=false` : sans cela le super admin est bloqué à l'étape de double authentification (obligatoire
  pour lui). **La double authentification ne doit jamais être désactivée en production** ;
- les migrations de la base de démonstration se lancent avec `APP_ENV=testing` : la garde de migration (sauvegarde
  vérifiable obligatoire sur un environnement non vide) refuse sinon, ce qui est son rôle en production.

## Changer l'état d'abonnement de la démo (pour tester le bandeau)

Sur la base SQLite de démonstration uniquement (adapter le chemin) :

```bash
# échéance dans 3 jours (bandeau jaune)
sqlite3 /chemin/demo.sqlite "update tenant_subscriptions set current_period_ends_at = datetime('now','+3 days') where tenant_id=2;"
# expiré (bandeau rouge, consultation seule)
sqlite3 /chemin/demo.sqlite "update tenant_subscriptions set current_period_ends_at = datetime('now','-1 day') where tenant_id=2;"
# remis à 30 jours
sqlite3 /chemin/demo.sqlite "update tenant_subscriptions set current_period_ends_at = datetime('now','+30 days') where tenant_id=2;"
```

## Recréer ou supprimer

Recréer (avec **votre** mot de passe) :

```bash
export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/chemin/demo.sqlite LARAVEL_STORAGE_PATH=/chemin/demo-storage
touch /chemin/demo.sqlite
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force   # à rejouer aussi après chaque évolution des rôles
php artisan platform:super-admin superadmin@demo.test --password='<votre mot de passe, 12 caractères minimum>'
```

Puis, pour le serveur : repasser `APP_ENV=local` (et `SECURITY_2FA_ENABLED=false` si besoin), et lancer
`php -S 127.0.0.1:8770 -t public routeur.php`.

Supprimer : arrêter le serveur (`pkill -f "php -S 127.0.0.1:8770"`) puis effacer le dossier de la démonstration.

## Garde-fous en place (production)

- **Aucun compte de démonstration par défaut** : `DatabaseSeeder` n'insère aucun utilisateur ; `RolesAndPermissionsSeeder`
  ne crée que des rôles et des permissions.
- `demo:setup` et `demo:clear` refusent de s'exécuter hors développement (`GuardsDemoCommands` : environnement,
  hôte local, une seule entreprise) ; aucune option ne contourne ce refus.
- `TestDataSeeder` applique la même garde (depuis l'audit).
- `php artisan db:seed` (sans `--class`) n'est plus le chemin d'initialisation : `DatabaseSeeder` appelle des seeders par entreprise et s'arrête avec « aucun tenant courant » (fail closed, rien n'est écrit). Utiliser `RolesAndPermissionsSeeder`, `SubscriptionPlanSeeder` ; les données par entreprise sont créées par `TenantProvisioner`.
- `notifications:test` crée un compte d'aide avec un mot de passe aléatoire inutilisable (et non plus `password`).
- Les identifiants `*@demo.test` ne figurent dans aucun fichier du dépôt (les tests utilisent des adresses fictives
  créées à la volée dans une base en mémoire).
- Avant toute mise en production : vérifier qu'aucun utilisateur `*@demo.test` n'existe
  (`php artisan tinker --execute="echo App\Models\User::withoutGlobalScopes()->where('email','like','%@demo.test')->count();"` doit afficher 0).
