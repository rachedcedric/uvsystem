# Préparation à la production — état et règles

Statuts : 🟢 VALIDÉ · 🟠 À VALIDER · 🔴 BLOQUANT · ⚪ NON DÉTERMINÉ (jamais transformé en hypothèse).
**La production n'est pas migrée. Rien dans ce document ne doit être exécuté automatiquement sur elle.**

## 1. État

| Point | Statut | Détail |
|---|---|---|
| Version MySQL de production | ⚪ | Non déterminée : absente du dépôt (`composer.json` ne fixe que PHP ≥ 8.2 et Laravel 12). À relever par le demandeur (hébergeur / phpMyAdmin : `SELECT VERSION();` sur la base **de production en lecture**, ou page « MySQL » de l'hébergeur) |
| Tests sur MariaDB 12.3.3 | 🟢 pour MariaDB, 🟠 pour la production | Suite complète : 1226 réussis, 0 échec, 16 ignorés (DDL réservés à SQLite, refaits à la main : rollback complet + remigration sans erreur). **Ce n'est pas une validation MySQL** |
| Tests sur la version MySQL de production | 🟠 | À faire une fois la version connue (voir §2) |
| Migrations | 🟠 | Chaîne complète, rollback et remigration validés sur MariaDB ; non rejoués sur la version de production |
| Seeders | 🟠 | Seeder de rôles rejoué 2 fois sur la démonstration (SQLite) puis sur MariaDB : aucun doublon, aucune perte, idempotent. Non rejoué sur une base de production |
| Compatibilité SQL | 🟠 | Fonctions propres au moteur : `DATE_FORMAT` (`AnalyticsService`, `StatisticsService`, `FournisseurController`, pages/widget de scoring) et `FIELD()` (page et widget de scoring). Elles n'existent pas dans SQLite (d'où le 500 de `agent-scoring-page` en SQLite) ; elles passent sur MariaDB, y compris avec zéro agent. À reconfirmer sur la version de production |
| Comportement « aucun tenant courant » de `db:seed` | 🟢 | Intentionnel : le seeder par défaut s'arrête sans rien écrire |
| Migration de la production | 🔴 | **Non faite.** Exige : sauvegarde vérifiée, `tenancy:preflight`, décision humaine sur les tables `clients*`, validation explicite |
| Instance de démonstration | 🟢 | Conservée jusqu'à validation finale ; comptes `*.demo.test` hors production (voir [DEMO.md](DEMO.md)) |

## 2. Valider une version MySQL (sur une copie jetable, jamais la production)

1. Relever la version exacte de production (⚪ → connue).
2. Installer la **même version** en local sur un port dédié (ex. 3399), `--skip-grant-tables`, datadir jetable ; ne jamais cibler le port ni la base de production.
3. Exécuter avec des variables explicites (`APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=<port>`, `DB_DATABASE=<jetable>`, `LARAVEL_STORAGE_PATH=<jetable>`) et **vérifier d'abord** que Laravel cible bien cette base (`DB::connection()->getDatabaseName()`).
4. `php artisan migrate --force`, `migrate:rollback --step=500 --force`, `migrate --force` : aucun échec.
5. Seeders : `RolesAndPermissionsSeeder` deux fois, puis comparaison (§3).
6. `php artisan test` complet ; exploration des écrans (`docs/MOBILE-INVENTORY-86.md`) pour détecter tout 500 propre au moteur.

## 3. Stratégie de rejeu des seeders sur une base existante (à exécuter seulement après validation explicite)

1. Sauvegarde **vérifiée** (restaurable) ; `php artisan tenancy:preflight` sans blocage.
2. Inventaire des bases concernées (local, recette, production) : une ligne par base, avec la date du dernier seeder.
3. Relevé **avant** (lecture seule) : nombre de rôles, de permissions par rôle, d'utilisateurs, d'entreprises, d'agents ; doublons de rôles/permissions.
4. Rejeu : `php artisan db:seed --class=RolesAndPermissionsSeeder --force` (pas de `db:seed` sans `--class`).
5. Relevé **après** et comparaison. Attendu : rôles `super_admin 0`, `master 61`, `admin 61`, `comptable 35`, `commercial 23`, `lecture_seule 14` ; **0 doublon** ; mêmes nombres d'utilisateurs, d'entreprises et d'agents.
6. En cas d'écart : arrêter, restaurer la sauvegarde, analyser avant tout nouvel essai.

Effet attendu du rejeu sur les droits (déjà validé en démonstration) : création du rôle `lecture_seule` ; retrait de `delete_agents` et
`view_activity_log` au commercial, de `edit_soldes` et `view_activity_log` au comptable. Aucun rôle ni permission n'est modifié par la
présente préparation.
