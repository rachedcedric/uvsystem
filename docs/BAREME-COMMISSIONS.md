# Barème des commissions

Le barème fourni aux masters compte **14 paliers**, de 1 000 000 à 74 999 999 FCFA de volume de ventes par jour. Il est défini
à un seul endroit : `app/Support/CommissionScale.php`.

| Volume de ventes | Commission | | Volume de ventes | Commission |
|---|---|---|---|---|
| 1 000 000 – 1 499 999 | 4 135 | | 10 000 000 – 14 999 999 | 26 755 |
| 1 500 000 – 1 999 999 | 5 470 | | 15 000 000 – 19 999 999 | 34 270 |
| 2 000 000 – 2 499 999 | 6 630 | | 20 000 000 – 29 999 999 | 39 080 |
| 2 500 000 – 2 999 999 | 7 020 | | 30 000 000 – 44 999 999 | 67 525 |
| 3 000 000 – 3 999 999 | 8 935 | | 45 000 000 – 59 999 999 | 72 845 |
| 4 000 000 – 4 999 999 | 12 445 | | 60 000 000 – 74 999 999 | 74 925 |
| 5 000 000 – 7 499 900 | 17 550 | | | |
| 7 500 000 – 9 999 999 | 19 840 | | | |

- En dessous de 1 000 000 FCFA : aucune commission.
- Au-delà de 74 999 999 FCFA : la commission du dernier palier (74 925) s'applique.
- **Écart connu :** le palier 5 000 000 se termine à **7 499 900** (valeur du barème fourni) alors que le suivant commence à
  7 500 000. Un volume de 7 499 901 à 7 499 999 FCFA ne tombe dans aucun palier et ne génère pas de commission. Si ce n'est pas
  voulu, remplacer `7_499_900` par `7_499_999` dans `CommissionScale.php`, puis relancer la commande ci-dessous.

## Nouveaux masters

Ils reçoivent ce barème à leur création (`PalierCommissionSeeder`, appelé par `TenantProvisioner`).

## Masters existants

Les paliers d'un master vivent dans sa propre table : changer le barème dans le code ne modifie pas les masters déjà créés. Une
commande les met à jour. **À lancer une fois après le déploiement :**

```bash
php artisan commissions:apply-scale --dry-run   # simulation : rien n'est modifié, résultat par entreprise
php artisan commissions:apply-scale             # application
php artisan commissions:apply-scale --tenant=3  # une seule entreprise
```

Pour chaque master :

- un palier du barème déjà présent (mêmes seuils) est mis à jour (commission) et réactivé ;
- un palier du barème absent est créé ;
- tout autre palier **actif** est **désactivé, jamais supprimé** : le calcul ne regarde que les paliers actifs, donc plus aucun
  chevauchement avec l'ancien barème, et un palier personnalisé reste récupérable (le master peut le réactiver) ;
- la commande est idempotente : un second passage ne change plus rien.

Elle ne modifie que `paliers_commission`. Les commissions **déjà calculées** (table `commissions`) ne sont pas recalculées.

C'est une commande et non une migration volontairement : elle change des taux de commission réels, donc elle se lance en
connaissance de cause, après avoir regardé la simulation.

## Écrans

Le tableau « Barème » (espace Filament) et le barème de référence du PDF des commissions lisent les paliers **du master** en
base ; ils suivent donc ses ajustements. L'application mobile faisait déjà de même.
