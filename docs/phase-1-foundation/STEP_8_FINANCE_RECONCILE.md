# Phase 1 — Step 8 : commande `finance:reconcile` (lecture seule)

Source : `PHASE_1_IMPLEMENTATION_PLAN.md` ligne 8 (« Commande `finance:reconcile` (lecture seule) + contrôles R-1…R-7 + test », dépend de l'étape 2, critère « rapport sans écart sur jeux seedés »), contrôles définis dans `FINANCIAL_RECONCILIATION_AUDIT.md` §4. Aucune migration, route, modèle ni écran modifié.

## Usage
```
php artisan finance:reconcile {organization_id} [--month=YYYY-MM | --months=12] [--sample=20] [--json]
```
Code de sortie : `0` aucun écart · `1` au moins un écart · `2` entrée invalide. La commande n'écrit **rien** (ni donnée, ni audit, ni file) ; l'organisation est un argument d'opérateur (jamais une entrée de requête). Les tables partagées avec le Mode Test sont filtrées sur `sandbox_id IS NULL` ; les paiements, remboursements et stocks de test vivent dans des tables `sandbox_*` jamais lues.

## Contrôles
| Code | Vérification | Source de comparaison |
|---|---|---|
| R-1 | Σ créances de factures émises + Σ exposition non facturée = Σ reste dû des commandes confirmées | `FinanceReceivablesService` (`totalAsOf`, `orderExposureQuery`, `orderExposureSummaryAsOf`) |
| R-2 | Solde de chaque compte configuré = ouverture + paiements − remboursements − paiements sortants | `FinancialAccountBalanceService` recalculé par requêtes indépendantes ; comptes sans position d'ouverture signalés, non vérifiés |
| R-3 | Encaissé net du mois (rapport) = Σ allocations postées − remboursements du mois | `FinanceMonthlyReportService` |
| R-4 | Facturé net du mois (rapport) = Σ factures émises − Σ avoirs émis | `FinanceMonthlyReportService` |
| R-5 | Par commande `encaissé − remboursé ≥ 0` ; par paiement posté `montant = Σ allocations` | SQL agrégé |
| R-6 | Aucun document émis modifié depuis l'émission (factures émises/remplacées, bons de livraison émis) | `DocumentSnapshotVerifier`, par lots de 200 |
| R-7 | Stock : `solde = Σ (quantity_after − quantity_before)` des mouvements, par entrepôt/variante | SQL agrégé (soldes sans mouvement et mouvements sans solde inclus) |

## Limites connues (volontaires)
- R-1 est une égalité exacte (4 décimales). `totalAsOf` est exact au 0,0001 alors que l'exposition applique le seuil de règlement (0,005 MAD) : un résidu inférieur au seuil peut produire un écart de 0,0001 signalé, non masqué : chaque variance est restituée à la décimale exacte avec une `classification` (`sub_centime_residue` si |variance| < 0,0050, sinon `material`) ; la classification n'altère jamais le verdict (toute variance non nulle = échec) (voir `STEP_2_CUSTOMER_BALANCE_FIX.md` §5).
- R-1 est agrégé (écart global, pas de liste de commandes). R-6 charge les lignes de chaque document : sur une grande organisation, lancer hors heures de pointe.
- La commande n'est pas planifiée ; aucune alerte automatique (hors périmètre).

## Tests
`tests/Feature/Finance/FinanceReconcileCommandTest.php` (11) : jeu seedé sans écart ; R-1 avec exposition ouverte non nulle ; lecture seule (aucune requête d'écriture, tables identiques) ; détection d'un écart de stock, d'un paiement ≠ allocations, d'un sur-remboursement (R-3/R-5/R-7) ; instantané de facture altéré (R-6) ; isolement entre organisations ; R-2 avec position d'ouverture ; options `--sample`/`--months`/`--month` validées (exit 2) ; variances exactes classées ; lecture seule aussi sur jeu en échec ; forme JSON stable.
