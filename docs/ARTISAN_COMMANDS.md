# Commandes Artisan - MODELEC CI

Ce document décrit toutes les commandes Artisan personnalisées du système UV.

---

## 📊 Analytics

### `analytics:show`

Affiche les analytics et prédictions du système.

```bash
# KPIs du mois (par défaut)
php artisan analytics:show kpis

# KPIs par période
php artisan analytics:show kpis --period=week
php artisan analytics:show kpis --period=quarter
php artisan analytics:show kpis --period=year

# Prédictions cash-flow
php artisan analytics:show cashflow
php artisan analytics:show cashflow --days=60

# Scoring des agents
php artisan analytics:show scoring

# Tendances mensuelles
php artisan analytics:show trends

# Export JSON
php artisan analytics:show kpis --export
```

**Options:**

-   `type` : Type d'analyse (`kpis`, `cashflow`, `scoring`, `trends`)
-   `--period` : Période (`today`, `week`, `month`, `quarter`, `year`)
-   `--days` : Nombre de jours pour les prédictions (défaut: 30)
-   `--export` : Exporter en JSON

---

## 📧 Notifications Email (Resend)

### `resend:test`

Teste l'intégration Resend.

```bash
# Test simple
php artisan resend:test

# Test vers une adresse spécifique
php artisan resend:test contact@uvsystem.pro

# Types de tests
php artisan resend:test --type=simple
php artisan resend:test --type=rich
php artisan resend:test --type=notification
```

### `notifications:test`

Teste les notifications métier.

```bash
# Test alerte dette
php artisan notifications:test debt-warning --agent=1

# Test rappel paiement
php artisan notifications:test payment-reminder --agent=1

# Test dette critique
php artisan notifications:test debt-critical --agent=1
```

---

## 📱 SMS (Twilio)

### `sms:test`

Teste l'envoi de SMS.

```bash
# Test simple
php artisan sms:test +22501234567

# Types de SMS
php artisan sms:test +22501234567 --type=simple
php artisan sms:test +22501234567 --type=debt
php artisan sms:test +22501234567 --type=reminder
php artisan sms:test +22501234567 --type=transaction
php artisan sms:test +22501234567 --type=remboursement

# Avec agent spécifique
php artisan sms:test --type=debt --agent=1
```

### `sms:reminders`

Envoie des rappels SMS aux agents endettés.

```bash
# Mode test (sans envoyer)
php artisan sms:reminders --dry-run

# Envoyer aux agents avec ratio dette > 70%
php artisan sms:reminders --min-ratio=70

# Limiter le nombre d'envois
php artisan sms:reminders --limit=50

# Production
php artisan sms:reminders --min-ratio=80
```

**Options:**

-   `--min-ratio` : Ratio dette minimum (défaut: 70)
-   `--dry-run` : Mode simulation
-   `--limit` : Nombre max de SMS (défaut: 100)

---

## 💰 Recouvrement Automatisé

### `recovery:process`

Exécute le processus de recouvrement automatisé.

```bash
# Afficher les statistiques
php artisan recovery:process --stats

# Traiter tous les niveaux
php artisan recovery:process --auto

# Traiter un niveau spécifique
php artisan recovery:process --level=warning --auto
php artisan recovery:process --level=alert --auto
php artisan recovery:process --level=critical --auto

# Forcer le traitement (ignore les délais)
php artisan recovery:process --level=critical --auto --force
```

**Niveaux d'escalade:**
| Niveau | Ratio Dette | Action |
|--------|-------------|--------|
| `warning` | 70-79% | Email de rappel |
| `alert` | 80-89% | Email + SMS urgent |
| `critical` | 90-99% | Tous canaux + alerte manager |
| `blocked` | 100%+ | Suspension automatique |

**Options:**

-   `--level` : Niveau à traiter (`warning`, `alert`, `critical`, `all`)
-   `--auto` : Mode automatique (pas de confirmation)
-   `--force` : Ignorer les délais entre relances
-   `--stats` : Afficher uniquement les statistiques

---

## 📄 Rapports Planifiés

### `reports:generate`

Génère et envoie des rapports.

```bash
# Rapport quotidien PDF
php artisan reports:generate daily --format=pdf

# Rapport hebdomadaire Excel
php artisan reports:generate weekly --format=excel

# Rapport mensuel avec envoi email
php artisan reports:generate monthly --format=pdf --email=admin@uvsystem.pro

# Sauvegarder localement
php artisan reports:generate daily --format=pdf --save
```

**Types de rapports:**

-   `daily` : Résumé journalier (transactions, remboursements, alertes)
-   `weekly` : Synthèse hebdomadaire avec tendances
-   `monthly` : Rapport mensuel complet avec analytics

**Options:**

-   `--format` : Format de sortie (`pdf`, `excel`)
-   `--email` : Adresse email de destination
-   `--save` : Sauvegarder dans `storage/app/reports/`

---

## 🔄 Webhooks

### `webhooks:test`

Simule des événements webhook Resend en local.

```bash
# Simuler email envoyé
php artisan webhooks:test email.sent

# Simuler email délivré
php artisan webhooks:test email.delivered

# Simuler email ouvert
php artisan webhooks:test email.opened

# Simuler échec
php artisan webhooks:test email.bounced
```

**Événements disponibles:**

-   `email.sent` : Email envoyé
-   `email.delivered` : Email délivré
-   `email.opened` : Email ouvert
-   `email.clicked` : Lien cliqué
-   `email.bounced` : Email rejeté
-   `email.complained` : Spam signalé

---

## 📅 Planification (Scheduler)

Les commandes sont automatiquement planifiées dans `routes/console.php` :

| Commande                            | Fréquence    | Heure |
| ----------------------------------- | ------------ | ----- |
| `backup:run`                        | Quotidien    | 02:00 |
| `backup:clean`                      | Quotidien    | 03:00 |
| `reports:generate daily`            | Quotidien    | 06:00 |
| `reports:generate weekly`           | Lundi        | 07:00 |
| `reports:generate monthly`          | 1er du mois  | 08:00 |
| `recovery:process --level=warning`  | Quotidien    | 08:00 |
| `recovery:process --level=alert`    | Quotidien    | 09:30 |
| `sms:reminders`                     | Jours ouvrés | 10:00 |
| `recovery:process --level=critical` | Quotidien    | 11:00 |

Pour activer le scheduler en production :

```bash
# Crontab
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1

# Ou avec supervisor
[program:laravel-scheduler]
command=php /path-to-project/artisan schedule:work
```

---

## 🧪 Données de Test

### `db:seed --class=TestDataSeeder`

Génère des données de test réalistes.

```bash
# Générer 3 mois de données
php artisan db:seed --class=TestDataSeeder
```

Génère :

-   50-150 transactions UV par agent
-   Remboursements avec taux variables (40-95%)
-   Données réparties sur 3 mois
-   Moins d'activité le week-end (réaliste)

---

## 🛠️ Utilitaires

### Cache

```bash
# Vider le cache (nécessaire après modifications analytics)
php artisan cache:clear

# Optimiser pour production
php artisan optimize
```

### Debug

```bash
# Voir les routes disponibles
php artisan route:list --path=api

# Vérifier les erreurs de config
php artisan config:show mail
```

---

## 📊 Widgets Filament Dashboard

Les widgets suivants sont disponibles sur le dashboard admin :

| Widget                     | Description                          |
| -------------------------- | ------------------------------------ |
| `StatsOverview`            | CA journalier, dettes, agents actifs |
| `AdvancedKpisWidget`       | KPIs mensuels avec tendances         |
| `AgentScoringWidget`       | Tableau de scoring des agents        |
| `CashFlowPredictionWidget` | Graphique prédictions 30 jours       |
| `TopAgentsWidget`          | Classement des meilleurs agents      |
| `DebtAlertsWidget`         | Alertes dette en cours               |
| `RecoveryStatsWidget`      | Statistiques de recouvrement         |

---

## 📚 Voir aussi

-   [Configuration Email (Resend)](./RESEND_CONFIG.md)
-   [Authentification 2FA](./TWO_FACTOR_AUTH.md)
-   [API Documentation](./api/README.md)
-   [Architecture](../ARCHITECTURE.md)
