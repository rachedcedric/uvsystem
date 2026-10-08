# Inventaire factuel des écrans de l'APK — 2026-10-08

> **Statut du document : factuel.** Il décrit ce que le code contient et ce qui a été mesuré. Il ne choisit aucun écran pour
> la Phase 3 et ne modifie aucun droit, aucune isolation d'entreprise ni aucun workflow.

## 0. Règle de décision

| | Sens |
|---|---|
| 🟢 VALIDÉ | Preuve complète, y compris appareil Android réel si l'élément en dépend |
| 🟠 À VALIDER | Testé ou vérifié en partie ; une validation reste à faire (appareil réel, MySQL de production…) |
| 🔴 BLOQUANT | Empêche d'avancer |
| ⚪ NON DÉTERMINÉ | L'information n'existe pas dans le code ni dans les sources retrouvées. **Jamais transformée en hypothèse** |

## 1. Cadrage reçu (établi par le demandeur)

- Périmètre APK : **78 écrans Master entreprise + 3 écrans Espace Agent + 5 écrans de sécurité partagés = 86**.
- Console Super Admin et fonctions globales Super Admin : **hors périmètre APK**.
- Fournisseurs, utilisateurs, catégories et paramètres : ressources du **Master de l'entreprise**.
- Connexion APK actuelle (inchangée) : master, admin, comptable, commercial, lecture seule ; super admin refusé. L'espace agent
  dépend d'une **fiche agent liée** à un compte autorisé (pas un rôle autonome). **Aucun rôle ni permission n'est modifié.**
- Pilote officiel : **Ventes UV** (liste, fiche, création). Non généralisé avant la validation sur appareil Android réel.

## 2. Les 24 écrans de la Phase 3

> **Les 24 écrans Phase 3 ne sont pas encore déterminés.** ⚪

Sources cherchées, sans résultat : code (`app`, `resources`, `routes`, `database`, `config`), tous les fichiers `docs/`, `README.md`,
historique git (messages et contenu, toutes branches, remises), mémoire du projet, autres sessions de travail, graphe d'index du dépôt.
La seule occurrence de « 24 écrans » retrouvée provenait de documents rédigés pendant cette phase, pas d'une spécification.

Les 42 pages Livewire, 14 ressources CRUD et 9 exports sont des **inventaires techniques** : ils ne constituent pas les 24 écrans
fonctionnels. La sélection reste à faire **par le demandeur** : la colonne `selection_phase3` du fichier
[`mobile-inventory.csv`](mobile-inventory.csv) est prévue pour cela (vide volontairement).

## 3. Réconciliation avec les 78 / 3 / 5

Mesure sur le code du 2026-10-08. **Les nombres ci-dessous ne sont pas rapprochés de force** : la règle de comptage ayant produit
« 86 » n'est pas retrouvable dans le code.

| Catégorie | Cadrage | Inventaire factuel | Écart | Statut |
|---|---|---|---|---|
| Master entreprise | 78 | **75** écrans certains (+ 1 redirection « Mon espace agent » à classer ; + 1 écran « Sauvegardes » exclu car réservé au Super Admin) | −3 ; −2 en ajoutant la redirection ; −1 en ajoutant aussi Sauvegardes (non tranché) | ⚪ règle de comptage non déterminée |
| Espace Agent | 3 | **3** (`agent-dashboard`, `my-transactions`, `my-remboursements`) | 0 | 🟢 concordant (nombre) |
| Sécurité partagée | 5 | **7 candidats** : connexion, sécurité du compte (2FA), activation 2FA (web), appareils connectés (web), code 2FA (web), mot de passe oublié, réinitialisation | +2 | ⚪ les 5 retenus ne sont pas identifiés |
| **Total** | **86** | **87 instances d'écran mesurées** (dont 1 hors périmètre) | | ⚪ |

Unité de comptage utilisée ici : une page ou une vue distincte (liste, fiche, création, modification d'une ressource = 4 unités).
Les 9 exports (téléchargements) et les routes techniques de l'annexe A ne sont **pas** comptés.

## 4. Inventaire

Rôles : ordre **M**aster, **A**dmin, **C**omptable, **C**ommercial (K), **L**ecture seule ; ✔ accès (200), ✖ refusé (403), ∅ introuvable (404),
↪ redirection (déjà connecté ou invité). Mesurés par requête HTTP réelle sur un jeu de données de test (SQLite), un compte par rôle.
Invité : redirigé vers la connexion sur tous les écrans protégés. Super Admin : refusé sur tous les écrans `/m` et `/master` (403).

### 4.1 Master entreprise — 75 écrans certains
| # | Nom | Route | Composant | Rôles M A C K L | Permissions | Statut mobile | Priorité | Dépendances | Statut |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Accueil | `/m` | Mobile\Home | ✔ ✔ ✔ ✔ ✔ | aucune permission spécifique (tout compte d'entreprise connecté) | Natif /m | ⚪ non attribuée | HomeDashboard | 🟠 À valider (appareil) |
| 2 | Plus | `/m/plus` | Mobile\More | ✔ ✔ ✔ ✔ ✔ | aucune permission spécifique ; chaque rubrique est filtrée par ses propres droits | Natif /m | ⚪ non attribuée | — | 🟠 À valider (appareil) |
| 3 | Notifications | `/m/notifications` | Mobile\Notifications | ✔ ✔ ✔ ✔ ✔ | view_notifications côté données ; écran ouvert à tout compte d'entreprise connecté | Natif /m | ⚪ non attribuée | — | 🟠 À valider (appareil) |
| 4 | Ventes UV — liste | `/m/ventes` | Mobile\Ventes\Index | ✔ ✔ ✔ ✔ ✔ | UvTransaction.viewAny → view_uv_transactions; export_uv_transactions | Natif /m | Pilote officiel | MonthOverview, PeriodStats, UvTransaction | 🟠 À valider (appareil) — testé auto + navigateur |
| 5 | Ventes UV — nouvelle vente | `/m/ventes/nouvelle` | Mobile\Ventes\Create | ✔ ✔ ✖ ✔ ✖ | UvTransaction.create → create_uv_transactions | Natif /m | Pilote officiel | MasterSoldeService, OperationDateTime, Money, UvDistributionService, Agent, UvTransaction | 🟠 À valider (appareil) — testé auto + navigateur |
| 6 | Ventes UV — fiche | `/m/ventes/1` | Mobile\Ventes\Show | ✔ ✔ ✔ ✔ ✔ | UvTransaction.create → create_uv_transactions; Gate.view(instance); Gate.update(instance); Gate.delete(instance) | Natif /m | Pilote officiel | UvDistributionService, UvTransaction | 🟠 À valider (appareil) — testé auto + navigateur |
| 7 | Remboursements — liste | `/m/remboursements` | Mobile\Remboursements\Index | ✔ ✔ ✔ ✔ ✔ | Remboursement.viewAny → view_remboursements; export_remboursements | Natif /m | ⚪ non attribuée | PeriodStats, PaymentMethod, Remboursement | 🟠 À valider (appareil) |
| 8 | Remboursements — nouveau | `/m/remboursements/nouveau` | Mobile\Remboursements\Create | ✔ ✔ ✔ ✖ ✖ | Remboursement.create → create_remboursements | Natif /m | ⚪ non attribuée | Fcfa, OperationDateTime, Money, RemboursementService, ShortReferenceGenerator, Agent | 🟠 À valider (appareil) |
| 9 | Remboursements — fiche | `/m/remboursements/1` | Mobile\Remboursements\Show | ✔ ✔ ✔ ✔ ✔ | Gate.view(instance); Gate.delete(instance) | Natif /m | ⚪ non attribuée | PaymentMethod, Remboursement | 🟠 À valider (appareil) |
| 10 | Agents — liste | `/m/agents` | Mobile\Agents\Index | ✔ ✔ ✔ ✔ ✔ | Agent.viewAny → view_agents | Natif /m | ⚪ non attribuée | Agent | 🟠 À valider (appareil) |
| 11 | Agents — fiche | `/m/agents/1` | Mobile\Agents\Show | ✔ ✔ ✔ ✔ ✔ | Gate.view(instance); Gate.delete(instance) | Natif /m | ⚪ non attribuée | UvDistributionService, Agent | 🟠 À valider (appareil) |
| 12 | Caisse — accueil | `/m/caisse` | Mobile\Caisse\Index | ✔ ✔ ✔ ✖ ✔ | CaisseMouvement.viewAny → view_caisse | Natif /m | ⚪ non attribuée | CaissePeriodResolver, CaisseService, CaisseMouvement | 🟠 À valider (appareil) |
| 13 | Caisse — mouvement | `/m/caisse/mouvement` | Mobile\Caisse\Create | ✔ ✔ ✔ ✖ ✖ | CaisseMouvement.create → create_caisse | Natif /m | ⚪ non attribuée | CaissePeriodResolver, CaisseService, Fcfa, OperationDateTime, Money, CaisseCategorie | 🟠 À valider (appareil) |
| 14 | Caisse — journée | `/m/caisse/2026-10-08` | Mobile\Caisse\Jour | ✔ ✔ ✔ ✖ ✔ | CaisseMouvement.viewAny → view_caisse | Natif /m | ⚪ non attribuée | CaissePeriodResolver, CaisseService, CaisseMouvement | 🟠 À valider (appareil) |
| 15 | Approvisionnements — liste | `/m/approvisionnements` | Mobile\Approvisionnements\Index | ✔ ✔ ✔ ✔ ✔ | Approvisionnement.viewAny → view_approvisionnements; view_approvisionnements | Natif /m | ⚪ non attribuée | PeriodStats, Approvisionnement | 🟠 À valider (appareil) |
| 16 | Approvisionnements — nouveau | `/m/approvisionnements/nouveau` | Mobile\Approvisionnements\Create | ✔ ✔ ✖ ✔ ✖ | Approvisionnement.create → create_approvisionnements | Natif /m | ⚪ non attribuée | ApprovisionnementService, MasterSoldeService, Fcfa, OperationDateTime, Money, Approvisionnement | 🟠 À valider (appareil) |
| 17 | Charges — liste | `/m/charges` | Mobile\Charges\Index | ✔ ✔ ✔ ✖ ✔ | Charge.viewAny → view_charges; export_charges | Natif /m | ⚪ non attribuée | ChargePeriods, Charge | 🟠 À valider (appareil) |
| 18 | Charges — nouvelle | `/m/charges/nouvelle` | Mobile\Charges\Create | ✔ ✔ ✔ ✖ ✖ | Charge.create → create_charges | Natif /m | ⚪ non attribuée | Fcfa, OperationDateTime, Money, Charge, ChargeCategorie | 🟠 À valider (appareil) |
| 19 | Charges — fiche | `/m/charges/1` | Mobile\Charges\Show | ✔ ✔ ✔ ✖ ✔ | Gate.view(instance); Gate.delete(instance) | Natif /m | ⚪ non attribuée | Charge | 🟠 À valider (appareil) |
| 20 | Commissions — liste | `/m/commissions` | Mobile\Commissions\Index | ✔ ✔ ✔ ✔ ✔ | Commission.viewAny → view_commissions; export_commissions | Natif /m | ⚪ non attribuée | BusinessPeriodResolver, CommissionService, MonthOverview, Commission, PalierCommission | 🟠 À valider (appareil) |
| 21 | Commissions — fiche | `/m/commissions/1` | Mobile\Commissions\Show | ✔ ✔ ✔ ✔ ✔ | Gate.view(instance) | Natif /m | ⚪ non attribuée | Commission | 🟠 À valider (appareil) |
| 22 | Dettes | `/m/dettes` | Mobile\Dettes\Index | ✔ ✔ ✔ ✔ ✔ | view_remboursements; view_remboursements_fournisseurs; create_remboursements_fournisseurs; create_remboursements; rôles: master, admin | Natif /m | ⚪ non attribuée | FournisseurDebtService, Fournisseur | 🟠 À valider (appareil) |
| 23 | Dettes — fiche agent | `/m/dettes/agent/1` | Mobile\Dettes\AgentDetail | ✔ ✔ ✔ ✔ ✔ | Gate.view(instance); view_remboursements; create_remboursements | Natif /m | ⚪ non attribuée | AgentHistory, Agent | 🟠 À valider (appareil) |
| 24 | Dettes — payer un fournisseur | `/m/dettes/fournisseur/1/payer` | Mobile\Dettes\PayFournisseur | ✔ ✔ ✔ ✖ ✖ | create_remboursements_fournisseurs | Natif /m | ⚪ non attribuée | FournisseurDebtService, Fcfa, OperationDateTime, Money, Fournisseur, PaymentMethod | 🟠 À valider (appareil) |
| 25 | Paiements fournisseurs | `/m/paiements-fournisseurs` | Mobile\RemboursementsFournisseurs\Index | ✔ ✔ ✔ ✔ ✔ | RemboursementFournisseur.viewAny → view_remboursements_fournisseurs | Natif /m | ⚪ non attribuée | RemboursementFournisseur | 🟠 À valider (appareil) |
| 26 | Statistiques | `/m/statistiques` | Mobile\Analytics\Statistics | ✔ ✔ ✔ ✔ ✔ | view_statistics; view_dashboard | Natif /m | ⚪ non attribuée | StatisticsService, Agent | 🟠 À valider (appareil) |
| 27 | Scoring — classement | `/m/scoring` | Mobile\Analytics\Scoring | ✔ ✔ ✔ ✔ ✔ | view_statistics; view_agents | Natif /m | ⚪ non attribuée | AnalyticsService | 🟠 À valider (appareil) |
| 28 | Scoring — fiche agent | `/m/scoring/1` | Mobile\Analytics\ScoringAgent | ✔ ✔ ✔ ✔ ✔ | view_statistics; view_agents | Natif /m | ⚪ non attribuée | AnalyticsService, Agent | 🟠 À valider (appareil) |
| 29 | Rapports et exports | `/m/rapports` | Mobile\Analytics\Reports | ✔ ✔ ✔ ✔ ✖ | view_reports; export_reports | Natif /m | ⚪ non attribuée | Agent, PaymentMethod | 🟠 À valider (appareil) |
| 30 | Journal d'activité | `/m/activite` | Mobile\Admin\ActivityLog | ✔ ✔ ✖ ✖ ✖ | view_activity_log | Natif /m | ⚪ non attribuée | Activity, User | 🟠 À valider (appareil) |
| 31 | Journal — détail | `/m/activite/11` | Mobile\Admin\ActivityShow | ✔ ✔ ✖ ✖ ✖ | view_activity_log | Natif /m | ⚪ non attribuée | Activity | 🟠 À valider (appareil) |
| 32 | Import de données | `/m/import` | Mobile\Admin\Import | ✔ ✔ ✖ ✖ ✖ | rôles: master, admin | Natif /m | ⚪ non attribuée | DataImporter | 🟠 À valider (appareil) |
| 33 | Mon abonnement | `/m/abonnement` | Mobile\Subscription\Show | ✔ ✔ ✖ ✖ ✖ | TenantSubscription.viewAny → rôles master / admin | Natif /m | ⚪ non attribuée | FeatureCatalog, SubscriptionAccessPolicy, SubscriptionBilling, SubscriptionEntitlements, SubscriptionManager, SubscriptionProration | 🟠 À valider (appareil) |
| 34 | Profil de l'entreprise | `/m/profil-entreprise` | Mobile\Account\Profile | ✔ ✔ ✖ ✖ ✖ | view_system_settings; edit_system_settings | Natif /m | ⚪ non attribuée | TenantDocumentBranding, TenantProfileUpdater, Tenant | 🟠 À valider (appareil) |
| 35 | Diagnostic des notifications | `/m/diagnostic-notifications` | Mobile\Admin\NotificationsDiagnostic | ✔ ✔ ✖ ✖ ✖ | rôles: master, admin | Natif /m | ⚪ non attribuée | FirebaseMessaging, NotificationDiagnostics, PushToken | 🟠 À valider (appareil) |
| 36 | Soldes Agents (Filament) | `/master/agent-soldes` | AgentSoldeResource (Filament, /master) | ✔ ✔ ✔ ✔ ✔ | view_soldes (canAccess) ; create/edit : edit_soldes | Web Filament dans la WebView (non natif) | ⚪ non attribuée | Agent, AgentSolde | 🟠 À valider (appareil) |
| 37 | Agents — création | `/m/g/agents/nouveau` | Crud\Form + Agents | ✔ ✔ ✖ ✔ ✖ | create_agents | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Fcfa, Agent | 🟠 À valider (appareil) |
| 38 | Agents — modification | `/m/g/agents/1/modifier` | Crud\Form + Agents | ✔ ✔ ✖ ✔ ✖ | edit_agents | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Fcfa, Agent | 🟠 À valider (appareil) |
| 39 | Fournisseurs — liste | `/m/g/fournisseurs` | Crud\Index + Fournisseurs | ✔ ✔ ✔ ✔ ✔ | view_fournisseurs | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Fournisseur | 🟠 À valider (appareil) |
| 40 | Fournisseurs — création | `/m/g/fournisseurs/nouveau` | Crud\Form + Fournisseurs | ✔ ✔ ✖ ✔ ✖ | create_fournisseurs | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Fournisseur | 🟠 À valider (appareil) |
| 41 | Fournisseurs — fiche | `/m/g/fournisseurs/1` | Crud\Show + Fournisseurs | ✔ ✔ ✔ ✔ ✔ | view_fournisseurs | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Fournisseur | 🟠 À valider (appareil) |
| 42 | Fournisseurs — modification | `/m/g/fournisseurs/1/modifier` | Crud\Form + Fournisseurs | ✔ ✔ ✖ ✔ ✖ | edit_fournisseurs | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Fournisseur | 🟠 À valider (appareil) |
| 43 | Paliers de commission — liste | `/m/g/paliers` | Crud\Index + Paliers | ✔ ✔ ✖ ✖ ✖ | master/admin, sinon view_system_settings | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Fcfa, PalierCommission, User | 🟠 À valider (appareil) |
| 44 | Paliers de commission — création | `/m/g/paliers/nouveau` | Crud\Form + Paliers | ✔ ✔ ✖ ✖ ✖ | master/admin, sinon edit_system_settings | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Fcfa, PalierCommission, User | 🟠 À valider (appareil) |
| 45 | Paliers de commission — fiche | `/m/g/paliers/7` | Crud\Show + Paliers | ✔ ✔ ✖ ✖ ✖ | master/admin, sinon view_system_settings | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Fcfa, PalierCommission, User | 🟠 À valider (appareil) |
| 46 | Paliers de commission — modification | `/m/g/paliers/7/modifier` | Crud\Form + Paliers | ✔ ✔ ✖ ✖ ✖ | master/admin, sinon edit_system_settings | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Fcfa, PalierCommission, User | 🟠 À valider (appareil) |
| 47 | Modes de paiement — liste | `/m/g/modes-paiement` | Crud\Index + ModesPaiement | ✔ ✔ ✖ ✖ ✖ | master/admin, sinon view_system_settings | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, PaymentMethod, User | 🟠 À valider (appareil) |
| 48 | Modes de paiement — création | `/m/g/modes-paiement/nouveau` | Crud\Form + ModesPaiement | ✔ ✔ ✖ ✖ ✖ | master/admin, sinon edit_system_settings | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, PaymentMethod, User | 🟠 À valider (appareil) |
| 49 | Modes de paiement — fiche | `/m/g/modes-paiement/1` | Crud\Show + ModesPaiement | ✔ ✔ ✖ ✖ ✖ | master/admin, sinon view_system_settings | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, PaymentMethod, User | 🟠 À valider (appareil) |
| 50 | Modes de paiement — modification | `/m/g/modes-paiement/1/modifier` | Crud\Form + ModesPaiement | ✔ ✔ ✖ ✖ ✖ | master/admin, sinon edit_system_settings | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, PaymentMethod, User | 🟠 À valider (appareil) |
| 51 | Catégories de charges — liste | `/m/g/categories-charges` | Crud\Index + CategoriesCharges | ✔ ✔ ✔ ✖ ✖ | edit_charges (toutes habilitations) | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Charge, ChargeCategorie, User | 🟠 À valider (appareil) |
| 52 | Catégories de charges — création | `/m/g/categories-charges/nouveau` | Crud\Form + CategoriesCharges | ✔ ✔ ✔ ✖ ✖ | edit_charges (toutes habilitations) | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Charge, ChargeCategorie, User | 🟠 À valider (appareil) |
| 53 | Catégories de charges — fiche | `/m/g/categories-charges/1` | Crud\Show + CategoriesCharges | ✔ ✔ ✔ ✖ ✖ | edit_charges (toutes habilitations) | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Charge, ChargeCategorie, User | 🟠 À valider (appareil) |
| 54 | Catégories de charges — modification | `/m/g/categories-charges/1/modifier` | Crud\Form + CategoriesCharges | ✔ ✔ ✔ ✖ ✖ | edit_charges (toutes habilitations) | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Charge, ChargeCategorie, User | 🟠 À valider (appareil) |
| 55 | Catégories de caisse — liste | `/m/g/categories-caisse` | Crud\Index + CategoriesCaisse | ✔ ✔ ✔ ✖ ✔ | view_caisse | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, CaisseCategorie, User | 🟠 À valider (appareil) |
| 56 | Catégories de caisse — création | `/m/g/categories-caisse/nouveau` | Crud\Form + CategoriesCaisse | ✔ ✔ ✔ ✖ ✖ | create_caisse | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, CaisseCategorie, User | 🟠 À valider (appareil) |
| 57 | Catégories de caisse — fiche | `/m/g/categories-caisse/7` | Crud\Show + CategoriesCaisse | ✔ ✔ ✔ ✖ ✔ | view_caisse | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, CaisseCategorie, User | 🟠 À valider (appareil) |
| 58 | Catégories de caisse — modification | `/m/g/categories-caisse/7/modifier` | Crud\Form + CategoriesCaisse | ✔ ✔ ✔ ✖ ✖ | edit_caisse | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, CaisseCategorie, User | 🟠 À valider (appareil) |
| 59 | Utilisateurs — liste | `/m/g/utilisateurs` | Crud\Index + Utilisateurs | ✔ ✔ ✖ ✖ ✖ | view_users | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, User | 🟠 À valider (appareil) |
| 60 | Utilisateurs — création | `/m/g/utilisateurs/nouveau` | Crud\Form + Utilisateurs | ✔ ✔ ✖ ✖ ✖ | create_users | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, User | 🟠 À valider (appareil) |
| 61 | Utilisateurs — fiche | `/m/g/utilisateurs/3` | Crud\Show + Utilisateurs | ✔ ✔ ✖ ✖ ✖ | view_users | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, User | 🟠 À valider (appareil) |
| 62 | Utilisateurs — modification | `/m/g/utilisateurs/3/modifier` | Crud\Form + Utilisateurs | ✔ ✔ ✔ ✖ ✖ | edit_users, ou sa propre fiche ; fiche master : master seulement | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, User | 🟠 À valider (appareil) |
| 63 | Sous-distributeurs — liste | `/m/g/sous-distributeurs` | Crud\Index + SousDistributeurs | ✔ ✔ ✔ ✔ ✔ | view_agents | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Fcfa, Agent, SousDistributeur, User | 🟠 À valider (appareil) |
| 64 | Sous-distributeurs — création | `/m/g/sous-distributeurs/nouveau` | Crud\Form + SousDistributeurs | ✔ ✔ ✖ ✔ ✖ | create_agents | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Fcfa, Agent, SousDistributeur, User | 🟠 À valider (appareil) |
| 65 | Sous-distributeurs — fiche | `/m/g/sous-distributeurs/1` | Crud\Show + SousDistributeurs | ✔ ✔ ✔ ✔ ✔ | view_agents | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Fcfa, Agent, SousDistributeur, User | 🟠 À valider (appareil) |
| 66 | Sous-distributeurs — modification | `/m/g/sous-distributeurs/1/modifier` | Crud\Form + SousDistributeurs | ✔ ✔ ✖ ✔ ✖ | edit_agents | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Fcfa, Agent, SousDistributeur, User | 🟠 À valider (appareil) |
| 67 | Agents partenaires — liste | `/m/g/agents-partenaires` | Crud\Index + AgentsPartenaires | ✔ ✔ ✔ ✔ ✔ | view_agents | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, AgentPartenaire, User | 🟠 À valider (appareil) |
| 68 | Agents partenaires — création | `/m/g/agents-partenaires/nouveau` | Crud\Form + AgentsPartenaires | ✔ ✔ ✖ ✔ ✖ | create_agents | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, AgentPartenaire, User | 🟠 À valider (appareil) |
| 69 | Agents partenaires — fiche | `/m/g/agents-partenaires/1` | Crud\Show + AgentsPartenaires | ✔ ✔ ✔ ✔ ✔ | view_agents | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, AgentPartenaire, User | 🟠 À valider (appareil) |
| 70 | Agents partenaires — modification | `/m/g/agents-partenaires/1/modifier` | Crud\Form + AgentsPartenaires | ✔ ✔ ✖ ✔ ✖ | edit_agents | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, AgentPartenaire, User | 🟠 À valider (appareil) |
| 71 | Ventes UV — modification | `/m/g/ventes/1/modifier` | Crud\Form + Ventes | ✔ ✔ ✖ ✔ ✖ | edit_uv_transactions | Natif /m (CRUD générique) | ⚪ non attribuée | MasterSoldeService, Field, MobileResource, Fcfa, Agent, UvTransaction | 🟠 À valider (appareil) |
| 72 | Remboursements — modification | `/m/g/remboursements/1/modifier` | Crud\Form + Remboursements | ✔ ✔ ✔ ✖ ✖ | edit_remboursements | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, Fcfa, Agent, PaymentMethod, Remboursement | 🟠 À valider (appareil) |
| 73 | Approvisionnements — modification | `/m/g/approvisionnements/1/modifier` | Crud\Form + Approvisionnements | ✔ ✔ ✖ ✔ ✖ | edit_approvisionnements | Natif /m (CRUD générique) | ⚪ non attribuée | MasterSoldeService, Field, MobileResource, Fcfa, Approvisionnement, Fournisseur | 🟠 À valider (appareil) |
| 74 | Charges — modification | `/m/g/charges/1/modifier` | Crud\Form + Charges | ✔ ✔ ✔ ✖ ✖ | edit_charges | Natif /m (CRUD générique) | ⚪ non attribuée | Field, MobileResource, OperationDateTime, Charge, ChargeCategorie | 🟠 À valider (appareil) |
| 75 | Mouvements de caisse — modification | `/m/g/mouvements-caisse/1/modifier` | Crud\Form + MouvementsCaisse | ✔ ✔ ✔ ✖ ✖ | edit_caisse | Natif /m (CRUD générique) | ⚪ non attribuée | CaissePeriodResolver, CaisseService, Field, MobileResource, Fcfa, CaisseCategorie | 🟠 À valider (appareil) |

### 4.2 À classer : redirection « Mon espace agent » (⚪)
| # | Nom | Route | Composant | Rôles M A C K L | Permissions | Statut mobile | Priorité | Dépendances | Statut |
|---|---|---|---|---|---|---|---|---|---|
| 76 | Mon espace agent (redirection) | `/m/espace-agent` | Closure | ✖ ✖ ✖ ✖ ✖ | fiche agent liée (exists) ; sinon 403 | Redirection → Filament /agent | ⚪ non attribuée | — | ⚪ Comptage non déterminé (redirection) |

### 4.3 Hors périmètre APK : Super Admin (⚪)
| # | Nom | Route | Composant | Rôles M A C K L | Permissions | Statut mobile | Priorité | Dépendances | Statut |
|---|---|---|---|---|---|---|---|---|---|
| 77 | Sauvegardes | `/m/sauvegardes` | Mobile\Admin\Backups | ✖ ✖ ✖ ✖ ✖ | rôle super_admin uniquement (refus pour tous les comptes /m) | Natif /m | ⚪ non attribuée | — | ⚪ Hors périmètre (Super Admin) |

### 4.4 Espace Agent — 3 écrans
| # | Nom | Route | Composant | Rôles M A C K L | Permissions | Statut mobile | Priorité | Dépendances | Statut |
|---|---|---|---|---|---|---|---|---|---|
| 78 | Espace agent — tableau de bord | `/agent/agent-dashboard` | AgentDashboard (Filament, /agent) | ✔ ✔ ✔ ✔ ✔ | rôle d'entreprise + fiche agent liée (données = sa propre fiche) | Web Filament dans la WebView (non natif) | ⚪ non attribuée | Agent, Remboursement, UvTransaction | 🟠 À valider (appareil) |
| 79 | Espace agent — mes ventes | `/agent/my-transactions` | MyTransactions (Filament, /agent) | ✔ ✔ ✔ ✔ ✔ | idem ; requête limitée à sa fiche, vide sans fiche | Web Filament dans la WebView (non natif) | ⚪ non attribuée | Agent, UvTransaction | 🟠 À valider (appareil) |
| 80 | Espace agent — mes remboursements | `/agent/my-remboursements` | MyRemboursements (Filament, /agent) | ✔ ✔ ✔ ✔ ✔ | idem | Web Filament dans la WebView (non natif) | ⚪ non attribuée | Agent, Remboursement | 🟠 À valider (appareil) |

### 4.5 Sécurité partagée — 7 candidats (5 attendus)
| # | Nom | Route | Composant | Rôles M A C K L | Permissions | Statut mobile | Priorité | Dépendances | Statut |
|---|---|---|---|---|---|---|---|---|---|
| 81 | Connexion | `/m/login` | Mobile\Login | ↪ ↪ ↪ ↪ ↪ | invité ; canAccessPanel(admin) à la connexion | Natif /m | ⚪ non attribuée | — | 🟠 À valider (appareil) — ⚪ appartenance aux « 5 écrans » non déterminée |
| 82 | Sécurité du compte (2FA) | `/m/securite` | Mobile\Admin\TwoFactor | ✔ ✔ ✔ ✔ ✔ | aucune permission spécifique (compte connecté ; gère sa propre 2FA) | Natif /m | ⚪ non attribuée | TwoFactorAuthService | 🟠 À valider (appareil) — ⚪ appartenance aux « 5 écrans » non déterminée |
| 83 | Activation de la 2FA (page web) | `/security/2fa` | TwoFactorEnrollmentController | ✔ ✔ ✔ ✔ ✔ | session authentifiée | Page web dans la WebView (non native) | ⚪ non attribuée | TwoFactorAuthService, User | 🟠 À valider (appareil) — ⚪ appartenance aux « 5 écrans » non déterminée |
| 84 | Appareils connectés et mot de passe | `/security/sessions` | AccountSecurityController | ✔ ✔ ✔ ✔ ✔ | session authentifiée (ses propres sessions) | Page web dans la WebView (non native) | ⚪ non attribuée | User, sessions | 🟠 À valider (appareil) — ⚪ appartenance aux « 5 écrans » non déterminée |
| 85 | Mot de passe oublié | `/forgot-password` | PasswordResetController | ↪ ↪ ↪ ↪ ↪ | invité | Page web dans la WebView (non native) | ⚪ non attribuée | User, sessions | 🟠 À valider (appareil) — ⚪ appartenance aux « 5 écrans » non déterminée |
| 86 | Réinitialisation du mot de passe | `/reset-password/xxxx` | PasswordResetController | ↪ ↪ ↪ ↪ ↪ | invité + jeton | Page web dans la WebView (non native) | ⚪ non attribuée | User, sessions | 🟠 À valider (appareil) — ⚪ appartenance aux « 5 écrans » non déterminée |
| 87 | Code de double authentification | `/2fa/challenge` | TwoFactorChallengeController | ↪ ↪ ↪ ↪ ↪ | session en attente de 2FA | Page web dans la WebView (non native) | ⚪ non attribuée | TwoFactorAuthService, User | 🟠 À valider (appareil) — ⚪ appartenance aux « 5 écrans » non déterminée |

## 5. Observations factuelles (aucune n'a été corrigée)

1. **Sauvegardes** (`/m/sauvegardes`) : refusé à tous les comptes autorisés sur l'APK (réservé au Super Admin, lui-même refusé sur `/m`) : écran présent dans le code mais inaccessible sur l'APK.
2. **Espace agent** : les 3 pages `/agent` répondent 200 aux 5 rôles ; sans fiche agent liée elles n'affichent aucune donnée (requête limitée à la propre fiche). « Mon espace agent » (`/m/espace-agent`) répond 403 sans fiche liée.
3. **Modification d'un utilisateur** : un compte (ex. comptable) peut ouvrir la modification de **sa propre** fiche (règle `UserPolicy::update`) ; la liste et la création d'utilisateurs lui restent fermées.
4. **Pages de sécurité sans `/m`** : activation 2FA, appareils connectés, code 2FA, mot de passe oublié/réinitialisé sont des pages web ouvertes dans la WebView, pas des écrans natifs.
5. **Paliers et modes de paiement** : accès pour master/admin ; pour les autres rôles, `view_system_settings` / `edit_system_settings` (qu'aucun n'a dans le seeder actuel).
6. **Lecture seule** : consultation de 15 écrans `/m` sur 31 sans paramètre ; toute création, modification, export et administration refusés (voir `docs/ROLES.md`).

## 6. Annexe A — routes présentes, non comptées comme écrans (statut ⚪ : classification à confirmer)

- **Exports (8)** : `/m/exports/caisse/historique`, `/m/exports/caisse/{date}`, `/m/exports/charges/{period}/{format}`, `/m/exports/commissions/{format}`, `/m/exports/dette-agent/{agent}/{format}`, `/m/exports/fiche-agent/{agent}`, `/m/exports/periode/{module}/{period}/{format}`, `/m/exports/rapport/{type}/{format}`.
- **Routes génériques des ressources « masquées »** (`ventes`, `remboursements`, `approvisionnements`, `charges`, `mouvements-caisse`) : liste, création et fiche existent techniquement sous `/m/g/<ressource>` ; l'interface n'expose que la **modification** (les listes, créations et fiches sont les écrans natifs). Comportement des 15 routes **non mesuré**.
- **`/m/g/agents`** (liste et fiche) : doublonnent les écrans natifs `/m/agents` ; seules création et modification sont comptées.

## 7. Limites de la mesure

- Accès mesurés sur **SQLite** ; l'exploration des écrans sans paramètre a aussi été rejouée sur **MariaDB 12.3** (0 erreur 500). **Aucune validation MySQL de production** (version de production ⚪ non déterminée).
- Jeu de données de test : une entreprise, un agent, une vente, un remboursement, etc. Les états « vide » et « beaucoup de données » ne sont pas mesurés écran par écran.
- Aucun écran n'est validé sur appareil Android réel : **tous restent 🟠**.
- Source des permissions : politiques (`app/Policies`), ressources CRUD (`app/Support/Mobile/Crud/Resources`) et composants Livewire ; extraction partiellement automatique, relue.
