# Dettes, découverts, annulations et rapports (Master — Web et mobile)

Mise à jour du 2026-10-25. Toutes les règles ci-dessous sont portées par des **services partagés** : l'interface
Web (`/master`) et l'application mobile (`/m`) appellent les mêmes classes, avec les mêmes permissions.

## 1. Solde fournisseur signé (dette / découvert)

`App\Services\FournisseurDebtService` — fournisseurs « Agent Premium » (à crédit) uniquement ; un fournisseur
« Banque » est payé comptant et ne crée jamais de dette.

```text
Solde = Σ approvisionnements à crédit − Σ remboursements non annulés
  > 0  dette du Master envers le fournisseur   (rouge)
  < 0  découvert = avance du Master            (vert, toujours affiché avec « − »)
  = 0  compte équilibré                        (neutre)
```

- Un remboursement supérieur à la dette est **accepté** : l'excédent devient un découvert.
- Un approvisionnement à crédit ultérieur **compense automatiquement** le découvert (le solde signé remonte vers 0).
  Aucune écriture supplémentaire : pas de double comptabilisation. `history()` explique chaque compensation
  (« dont 50 000 FCFA compensés par le découvert ») et chaque avance.
- Calcul exact en décimal (`bcmath`, 2 décimales), recalculé en direct, jamais mis en cache.
- `globalSummary()` additionne dettes et découverts **fournisseur par fournisseur** (l'avance chez A n'efface pas la
  dette envers B).
- Fiche fournisseur : Web `/master/fournisseurs/{id}`, mobile `/m/dettes/fournisseur/{id}`.

## 2. Annulation d'un remboursement fournisseur

`FournisseurDebtService::cancel($remboursement, $motif)` — menu « ⋯ » → « Annuler le remboursement ».

- Annulation **logique** : `cancelled_at`, `cancelled_by`, `cancellation_reason` ; la ligne reste dans l'historique
  (statut « Annulé », montant barré) et sort de tous les soldes.
- Motif obligatoire, transaction atomique avec verrou, refus d'une seconde annulation, entrée `activity_log`
  (`event = cancelled`, solde avant / après).
- Droit : policy `RemboursementFournisseurPolicy::cancel` (permission `delete_remboursements_fournisseurs`, jamais
  la lecture seule). Un remboursement annulé n'est plus modifiable.
- Double envoi : un paiement strictement identique (même fournisseur, montant, date, auteur) dans les 30 s est refusé.

## 3. Retour Agent (rebalancing)

- Type de fournisseur **« Agent »** et entrée de sélection **« Retour Agent »** (une par entreprise, créée par la
  migration, au provisionnement et à la demande).
- Formulaire d'approvisionnement : choisir « Retour Agent » affiche **« Sélectionner un agent »** (agents de
  l'entreprise uniquement, recherche par nom).
- Enregistrement (`RetourAgentService`) : **approvisionnement à crédit**, traité comme un Agent Premium, sur le compte
  fournisseur propre à l'agent « Retour Agent — {nom} » (`fournisseurs.agent_id`, créé automatiquement au premier
  retour, un par agent : les comptes des agents ne se mélangent jamais).
  - stock UV du Master **+montant** ;
  - dette du Master envers l'agent **+montant** : visible dans **Dettes fournisseurs**, payable (« Payer »),
    annulable, avec découvert et compensation comme tout fournisseur à crédit ;
  - la dette d'achats d'UV de l'agent **ne change pas** (une seule écriture : pas de double comptabilisation) ;
  - les commissions (calculées sur les ventes) ne changent pas.
- L'entrée de sélection « Retour Agent » ne reçoit jamais d'approvisionnement direct (refusé par
  `ApprovisionnementService`) et n'apparaît pas comme créancier. Entrées « Agent » ni modifiables ni supprimables.
- Rapports : catégorie « Retours agents » ; ces approvisionnements sont exclus de la catégorie « Approvisionnements ».

## 4. « Supprimer les mouvements de cette journée » (caisse)

`CaisseService::annulerJournee($jour, $motif, $confirmation)` — Web : journée de caisse ; mobile : `/m/caisse/{date}`.

- Annulation **logique** de tous les mouvements actifs du jour, regroupés par `cancellation_batch`. Un scope global
  (`CaisseMouvement::SCOPE_ACTIFS`) les retire de tous les soldes, totaux, catégories, listes et exports ; ils restent
  visibles dans « Mouvements annulés » et dans le journal d'activité (`event = day_cancelled`, références, motif).
- Confirmation renforcée : ressaisir la date (JJ/MM/AAAA) + motif. Journée future, journée déjà annulée et annulations
  simultanées refusées. Les journées de caisse étant indépendantes (solde remis à zéro à minuit), les autres journées
  ne changent pas.
- Droit : policy `CaisseMouvementPolicy::cancelDay` (master / admin ayant `delete_caisse`).
- L'export complet des données de l'entreprise conserve les mouvements annulés.

## 5. Rapports et exports, historique des dettes

- Catalogue unique : `App\Services\Reports\ReportCatalog` (ventes, approvisionnements, remboursements agents,
  paiements fournisseurs, commissions, dettes agents, dettes fournisseurs, crédits et découverts, retours agents,
  mouvements financiers, charges, situation des agents, historique des dettes et crédits).
- Périodes : `DateRange` (aujourd'hui, hier, 7 derniers jours, ce mois, mois précédent, dates libres). Fuseau
  `Africa/Abidjan`, **date de fin incluse** (filtre `[début, lendemain de la fin[`), fin avant début refusée.
- Export : `ReportExportController` — Web `GET /exports/rapports/{categorie}/{pdf|xlsx}`, mobile
  `GET /m/exports/rapports/{categorie}/{pdf|xlsx}` ; paramètres `periode`, `du`, `au`, `q` (et `type`, `statut` pour
  l'historique). Mêmes lignes et totaux qu'à l'écran ; Excel avec montants numériques et ligne de total.
- Écrans : mobile `/m/rapports` (refonte) et `/m/dettes/historique` ; Web « Rapports & Exports » (partie catalogue
  ajoutée, exports détaillés conservés), « Historique des dettes et crédits », et action « Exporter une période »
  sur les listes.
- Historique des dettes : `DebtHistoryService` reconstitue, pour chaque agent et fournisseur, solde d'ouverture,
  dettes de la période, paiements, solde à la date de fin (consultation d'une date passée).

## 6. Permissions et abonnements

- `exports_avances` n'est plus appliquée (fonctionnalité ouverte) : les exports de commissions, caisse, fiche agent
  et par période sont des exports PDF / Excel ordinaires, ouverts à tout Master dont l'offre inclut les exports
  (toutes les offres livrées). Cause du « 403 — Accès refusé » à l'export des commissions en offre Essentiel.
- La page 403 affiche désormais le motif précis quand l'application en fournit un.
- Les autres fonctionnalités d'offre (statistiques avancées, import, notifications push, sauvegardes dédiées,
  rapports planifiés) sont inchangées.

## 7. Double authentification

Facultative pour tous les comptes des entreprises (`security.authentication.2fa_required_for_roles = []`) : plus
de bandeau ni de compte à rebours ; activable dans les paramètres de sécurité ; une 2FA déjà activée reste exigée.
Toujours obligatoire pour le super admin de plateforme.

## 8. Déploiement

Migrations (additives, sans perte de données) :

| Migration | Effet | Retour arrière |
|---|---|---|
| `2026_10_25_100000_add_cancellation_to_remboursements_fournisseurs_table` | 3 colonnes nullables + index | supprime les colonnes (perd les annulations) |
| `2026_10_25_110000_add_retour_agent_supplier_type` | enum `type` + `AGENT`, colonne `fournisseurs.agent_id`, entrée « Retour Agent » par entreprise | retire `agent_id` (l'enum n'est pas rétréci) |
| `2026_10_25_120000_add_cancellation_to_caisse_mouvements_table` | 4 colonnes nullables + index | supprime les colonnes (les mouvements annulés redeviennent actifs) |

1. Sauvegarde vérifiée de la base (`deploy/scripts/backup.sh`), puis déploiement habituel (`deploy/scripts/deploy.sh`).
2. `php artisan migrate --force`, puis `php artisan optimize:clear` (la 2FA lit la configuration).
3. Optionnel : retirer `SECURITY_2FA_ENFORCE_FROM` du `.env` de production (sans effet désormais).
4. Les assets mobiles (nouvelles classes de `resources/css/mobile.css`) sont compilés par `npm run build` (Dockerfile).

Retour arrière : redéployer la version précédente. Les migrations peuvent rester en place (colonnes nullables,
schéma compatible), **mais l'ancien code ignore les annulations** : un remboursement fournisseur ou un mouvement de
caisse annulé recompterait dans les soldes, et l'entrée « Retour Agent » apparaîtrait comme un fournisseur ordinaire.
Avant un retour arrière, lister les annulations déjà faites :
`SELECT reference, cancelled_at FROM remboursements_fournisseurs WHERE cancelled_at IS NOT NULL;` et
`SELECT reference, cancelled_at FROM caisse_mouvements WHERE cancelled_at IS NOT NULL;` — s'il y en a, préférer un
correctif à un retour arrière. Ne lancer `migrate:rollback` qu'en connaissance de cause (perte des annulations).
