# Rôles et permissions (entreprise)

Cinq rôles d'entreprise, définis dans `RolesAndPermissionsSeeder` ; le rôle `super_admin` appartient à la plateforme
(`/platform`) et n'a accès à aucune donnée d'entreprise.

| Rôle | Pour qui | Résumé |
|---|---|---|
| `master` | Propriétaire du compte client | Toutes les permissions de l'entreprise ; seul à nommer ou modifier un master |
| `admin` | Administrateur du compte client | Mêmes permissions que le master, sauf nommer/toucher un master |
| `comptable` | Finances | Caisse, charges, remboursements, commissions, soldes en lecture, rapports et exports ; **ni** modification des soldes, **ni** journal d'activité |
| `commercial` | Terrain | Agents (création/modification, pas de suppression), ventes UV, fournisseurs, approvisionnements ; **ni** suppression d'agents, **ni** journal d'activité |
| `lecture_seule` | Consultation | Voit tableaux de bord, agents, ventes, remboursements, approvisionnements, fournisseurs, commissions, soldes, statistiques, caisse et charges. Ne modifie rien, n'exporte rien |

## Rôle `lecture_seule`

Les protections sont **côté serveur**, pas seulement des boutons masqués :

1. **Permissions** : uniquement des `view_*` (ni `view_users`, `view_system_settings`, `view_activity_log`, `view_reports`,
   ni aucun `export_*`, ni `access_api`).
2. **Modèles** : `App\Security\ReadOnlyGuard`, appelé par `BelongsToTenant` à chaque insertion, modification et
   suppression, refuse (403) toute écriture d'un compte en lecture seule, même via `saveQuietly()` ou `withoutEvents()`.
   Restent permis : le journal d'activité, l'appareil de notification, les soldes dérivés recalculés, le journal d'e-mails
   d'abonnement et la fiche du compte lui-même (mot de passe, 2FA). Le code de confiance (`runAs`, `withoutTenancy`,
   commandes, jobs) n'est jamais concerné.
3. **Routes** : middleware `not.read-only` sur tous les exports et téléchargements ; `BlockReadOnlyFormPages` refuse
   les pages `…/create` et `…/edit` du panneau web.
4. **Attribution** : ne se combine avec aucun autre rôle ; un compte qui aurait les deux est traité en lecture seule
   (fermé par défaut).
5. **API** : réservée à `access_api` (master/admin) ; un compte en lecture seule ne reçoit aucun jeton.

## Permissions d'accès API

`access_api` est exigée à la connexion (`POST /api/auth/login`) et sur toutes les routes protégées de l'API.
Seuls `master` et `admin` l'ont. L'application mobile (APK) utilise la session web, pas l'API.

## Mise à jour d'une base existante

Les rôles et permissions sont écrits par le seeder, pas par les migrations. **Sur chaque base déjà en service**
(local, recette, production) il faut relancer, après déploiement :

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder --force
```

Il crée `lecture_seule` et **retire** (`syncPermissions`) `delete_agents` et `view_activity_log` du commercial,
`edit_soldes` et `view_activity_log` du comptable. Sans cette commande, ces comptes gardent leurs anciens droits.

## Tests

`RoleAccessMatrixTest` (42 écrans × 5 rôles), `ReadOnlyRoleTest`, `LeastPrivilegeTest`,
`TenantBoundaryRegressionTest` (sondes inter-entreprises avec témoins), `SecurityHardeningTest`.
