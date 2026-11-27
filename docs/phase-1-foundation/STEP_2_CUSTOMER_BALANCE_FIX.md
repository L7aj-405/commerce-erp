# Phase 1 / Step 2 — F-01 : intégrité du solde financier client

> Branche `fix/phase-1-step-2-customer-balance`, créée depuis `origin/staging` @ `83715f1` (la PR #23 / Step 1 **est fusionnée** dans `staging`). Document mis à jour après la revue finale. Non commité, non poussé.

## 1. Défaut d'origine
`CustomerFinancialSummary::forCustomer()` calculait `outstanding = Σ factures émises du client − Σ paiements de TOUTES les commandes du client`, sans netter avoirs ni remboursements, puis planchait le résultat à zéro (`Money::outstanding`). Un paiement sur une commande **non facturée** B réduisait donc la créance d'une **facture** A. Chiffre affiché sur la fiche client **et** exposé par l'outil MCP `get_customer_balance_summary`.

## 2. Preuve de reproduction
Test permanent `tests/Feature/Finance/CustomerBalanceNoCrossOrderNettingTest.php`, exécuté **avant** le correctif (SQLite, 13 tests alors écrits) : **7 échecs + 3 erreurs**, dont :

| Cas | Attendu | Obtenu (ancien code) |
|---|---|---|
| A facturée 100 impayée, B non facturée avec 60 encaissés | `outstanding = 100.0000` | **`40.0000`** |
| A 100 payée 30, B 80 payée 60 | `70.0000` | `10.0000` |
| Paiement de A annulé, B payée | `100.0000` | `40.0000` |
| B facturée plus tard | `100.0000` puis `120.0000` | `40.0000` |
| Client 2 (acompte 500) n'affecte pas client 1 | client 2 = `40.0000` | `0.0000` |

(Les erreurs restantes : clé `refund_obligation` et méthode `customerPositionAsOf()` inexistantes.)

## 3. Cause racine
Agrégat unique « total facturé − total payé » au niveau **client**, au lieu d'un calcul **par commande**. Les paiements s'attachent à la commande (`payment_allocations.sales_order_id`, jamais à la facture) : seule une jointure par commande peut dire ce qui est dû sur une facture donnée.

## 4. Calcul corrigé
Aucune seconde formule : on **réutilise** `FinanceReceivablesService` (mêmes requêtes `obligationsQuery()` / `orderExposureQuery()` que les écrans « Créances »). Nouvelle méthode `customerPositionAsOf(Organization, customerId, storeIds, ?asOf = null)` :

- **`receivable`** = Σ sur les factures `Issued` du client de `max(facture − avoirs émis de SA commande − (encaissé − remboursé) de SA commande, 0)`, restreinte au client et aux boutiques visibles.
- **`overcollected`** (exposé comme `refund_obligation`) = Σ de ce qui a été payé **au-dessus** du net de la même facture : obligation **envers** le client, rapportée, jamais compensée avec une autre facture ni masquée par le plancher.
- **`unbilled`** = reste dû des commandes confirmées **sans** facture émise/remplacée, en agrégat SQL borné (l'ancienne boucle en mémoire plafonnée à 500 commandes disparaît).
- Les deux seaux sont **disjoints** : une commande est dans exactement un d'eux ⇒ aucune dette comptée deux fois.
- **Période : sans borne de date** (`asOf = null` ⇒ « tout document, quelle que soit sa date »). Comme l'ancien résumé, la fiche est une **position courante** : une commande ou facture postdatée (le droit de rétrodatage autorise n'importe quelle date) reste due. *Une première version bornait à « aujourd'hui » : la revue l'a repérée comme régression de compatibilité et corrigée ; deux tests la verrouillent.*
- **Précision (règle de règlement)** : un écart qui s'arrondit à zéro à la précision de la devise (0,0001 MAD) n'est ni créance ni remboursement (`Money::payableThresholdSql`, jumeau SQL de `Money::outstanding`). `ROUND(…, 4)` garde l'échelle interne exacte (SQLite soustrait les DECIMAL en flottant binaire, MySQL est exact).

`CustomerFinancialSummary` ne contient plus de formule de solde : il applique permissions et visibilité par boutique, puis délègue.

## 5. Précision : écart 0,0001 MAD entre `totalAsOf()` et `customerPositionAsOf()` — verdict
**Écart réel et volontaire, justifié et verrouillé par un test.** Deux définitions coexistent déjà dans le code :

| Vue | Règle sub-centime | Pourquoi |
|---|---|---|
| **Solde client / exposition par commande** (`customerPositionAsOf`, `orderExposureQuery`, `salesOrderReceivablesAsOf`, `Money::outstanding`, blocage de facture) | un reste < 0,005 MAD est **réglé** | convention de règlement : sans cela une commande réglée à la précision MAD ne pourrait pas être facturée (voir `CurrencyPrecisionSettlementTest`) ; l'ancien résumé client l'appliquait déjà |
| **Grand livre des créances** (`totalAsOf`, `refundObligationAsOf`, `breakdownAsOf`) | montant **exact** au 0,0001 | `FinanceMonthlyReportService` démontre « début + facturation − encaissements = fin » à la dernière décimale ; seuiller `totalAsOf` casserait cette identité de 0,0001 |

Décision : **ne rien arrondir dans les rapports Finance** (on ne modifie pas des totaux comptables) et **conserver la convention de règlement pour le solde client** (comportement historique, épinglé par `McpFinanceToolsTest::test_sub_centime_residue…`). Test `test_customer_balance_is_settlement_exact_while_the_ledger_receivables_stay_exact_to_the_last_decimal` : facture 13 306,0001 encaissée 13 306,0000 ⇒ solde client `0.0000` ; ligne du détail Créances `0.0001`. L'écart ne concerne que des résidus < 0,005 MAD. *Constat annexe (non corrigé)* : sur SQLite (pilote de test uniquement), `totalAsOf()` peut recevoir un flottant bruité sur ce cas précis ; MySQL est exact.

## 6. Revue des champs de la réponse (page client + MCP)
| Champ | Nature | Période | Définition | Consommateurs | Changement |
|---|---|---|---|---|---|
| `sales_total` | brut TTC | tout | Σ commandes confirmées visibles | page, MCP | aucun |
| `invoiced_total` | **brut** TTC | tout | Σ factures `Issued` visibles (non net des avoirs) | page (« Facturé »), MCP | aucun |
| `paid_total` | **brut** | tout | Σ allocations de paiements postés sur les commandes du client (facturées ou non) ; non net des remboursements ; boutiques visibles | page (« Encaissé »), MCP | aucun |
| `outstanding` | **net**, par commande | tout, courant | créance des factures émises (voir §4) | page (« Solde des factures émises »), MCP | **redéfini (F-01)** : l'ancienne valeur était fausse (n'est plus « facturé − payé ») |
| `unbilled_outstanding` | net | tout, courant | reste dû des commandes sans facture émise | page, MCP | même définition ; plus de plafond à 500 commandes ; mêmes règles sub-centime |
| `refund_obligation` | net | tout, courant | sur-encaisse par facture, due au client | MCP, props de page (**non affichée**) | **nouveau** champ additif ; `null` sans permission |

Compatibilité : toutes les clés historiques restent présentes avec le même type (`string` décimal à 4 chiffres ou `null`) ; seul `outstanding` change de valeur (correctif) ; une clé est ajoutée. Les deux tests de **forme exacte** du résumé (`CustomerStoreIsolationTest`) ont reçu la nouvelle clé (valeurs attendues inchangées). Un client JSON strict qui interdit les clés inconnues devrait être mis à jour.

**Champs potentiellement trompeurs (à corriger séparément, hors F-01)** : les libellés de la page sont exacts, mais la juxtaposition « Facturé 100 / Encaissé 60 / Solde des factures 100 » peut surprendre quand l'encaissé concerne une autre commande. Proposition distincte : afficher « Encaissé sur commandes facturées » (net) et « Acomptes sur commandes non facturées », et rendre `invoiced_total` net des avoirs. Non modifié ici pour ne pas changer le sens d'un champ existant.

## 7. Invariants financiers (tous couverts par des tests)
1. Un paiement sur la commande B ne réduit jamais la créance de la commande A ; aucune compensation entre clients.
2. Chaque créance de facture provient uniquement de sa propre commande ; total client = Σ des créances par commande.
3. Avoirs émis, remboursements postés, paiements annulés (statut `Reversed`) pris en compte par commande.
4. Une sur-encaisse est **détectée** (`refund_obligation`), pas écrasée.
5. Créance et exposition non facturée disjointes ; `créance + non facturé = Σ reste dû des commandes confirmées` (R-1).
6. Documents du Mode Test (`sandbox_id`) exclus.
7. Toute requête filtrée par `organization_id` ; seules les boutiques visibles comptent ; liste vide ⇒ zéro, jamais « tout ».
8. Aucun flottant applicatif ; `ROUND(…,4)` côté SQL.
9. Aucune écriture : ni paiement, ni allocation, ni facture modifiés ; aucune migration.

## 8. Fichiers modifiés (état final)
| Fichier | Changement |
|---|---|
| `app/Services/Finance/FinanceReceivablesService.php` | + `customerPositionAsOf()` et la constante `OPEN_ENDED_AS_OF` ; **aucun code existant modifié** (77 lignes ajoutées, 0 supprimée) |
| `app/Services/CustomerFinancialSummary.php` | calcul de `outstanding`/`unbilled_outstanding` délégué ; clé `refund_obligation` ; dépendance `FinanceReceivablesService` (remplace `SalesOrderPaymentCalculator`) ; docblock de sémantique des champs |
| `app/Services/Mcp/Sales/CustomerReadService.php` | définitions MCP (`outstanding`, `refund_obligation`, `unbilled_outstanding`) ; reformatage Pint de 4 `if` d'une ligne et d'un docblock (porte « Pint sur fichiers modifiés » du CI) |
| `tests/Feature/Sales/CustomerStoreIsolationTest.php` | deux assertions de forme exacte reçoivent `refund_obligation` (2 lignes) |
| `tests/Feature/Finance/CustomerBalanceNoCrossOrderNettingTest.php` | **nouveau** : 16 tests |
| `docs/phase-1-foundation/STEP_2_CUSTOMER_BALANCE_FIX.md` | ce document |
Aucune migration, route, modèle ni modification d'interface.

## 9. Tests ajoutés (16)
1 A facturée / B avec acompte ; 2 A partiellement payée ; 3 paiement annulé ; 4 retour partiel + avoir ; 5 sur-encaisse après avoir puis remboursement ; 6 B facturée plus tard ; 7 deux clients ; 8 plusieurs organisations (+ refus d'un client étranger par le service) ; 9 utilisateur limité à une boutique ; 10 isolation Mode Test ; 11 précision (`0.1`, `0.2`, `99999999.99`) ; 11b résidu sub-centime vs centime réel ; 11c **documents postdatés toujours dus** ; 11d **écart grand livre / règlement documenté** ; 12 réconciliation (§10) ; + `null` sans `payments.view`.

## 10. Réconciliation
Test 12, jeu mixte : (a) `outstanding` client = `totalAsOf()` (un seul client) ; (b) = Σ des lignes de `breakdownAsOf()` ; (c) `outstanding + unbilled_outstanding = Σ SalesOrderPaymentCalculator::remainingAmount()` des commandes confirmées ; (d) valeurs explicites 70 / 95. Verts. Hors sub-centime, les trois sources coïncident exactement.

## 11. Validation — exécutée lors de la revue finale (code final)
| Base | Périmètre | Résultat |
|---|---|---|
| SQLite | nouveau test (16) | 16/16 |
| SQLite | **suite complète** | **2309 / 2309** (17 896 assertions) |
| MySQL 8.4.3 (instance jetable locale) | Finance, Payments, Documents, Sales, Pos, Tenancy, Sandbox, Returns, Exchanges, Mcp, Unit/Architecture | **1009 / 1009** (8 861 assertions) |
| Tests d'architecture (portée tenant + précision flottante) | inclus dans les deux jeux | verts |
| Pint direct (`--test`) sur les 5 fichiers PHP modifiés/ajoutés | — | `passed` |
**Résultats antérieurs (passes intermédiaires, remplacées)** : avant correctif, 7 échecs + 3 erreurs sur le nouveau test (preuve de reproduction) ; une première passe avait 3 échecs corrigés ensuite (forme exacte du résumé ×2 ; flottant SQLite et seuil sub-centime) ; une version intermédiaire bornée à « aujourd'hui » a été corrigée avant ces résultats. MySQL lancé avec `--innodb-flush-log-at-trx-commit=0` (jetable, vitesse seulement). Le CI GitHub n'a **pas** été exécuté (aucun push).

**Incident de procédure** : pendant la revue, Pint a été lancé par erreur en mode correction sur tout `app/Services` (≈ 130 fichiers reformatés). Tous ont été **restaurés** (`git checkout`) ; l'arbre ne contient que les fichiers du §8 (vérifié par `git diff --name-only`). Pint n'est plus utilisé qu'en `--test`.

## 12. Sécurité (tenant, boutique, Mode Test)
Requêtes toutes filtrées par `organization_id` (`obligationsQuery`, `orderExposureQuery`, sous-requête `NOT EXISTS` sur factures) ; aucune recherche `find()` ni clé nue ; boutiques = `DocumentVisibility::storeIds()` ; `sandbox_id IS NULL` sur factures, avoirs et commandes ; permissions ⇒ `null` ; aucune écriture introduite. Les gardes d'architecture n'ont signalé aucune nouvelle requête non filtrée.

## 13. Risques restants (documentés, non modifiés)
1. `paid_total` brut (§6) et `invoiced_total` brut : juxtaposition potentiellement trompeuse ; correction séparée proposée.
2. Écart sub-centime grand livre / solde client : volontaire (§5) ; à connaître des comptables.
3. La page n'affiche pas `refund_obligation` : une sur-encaisse n'a pas d'alerte visuelle.
4. Rattachement par `invoices.customer_id` (instantané) : un changement de client après facturation n'est pas suivi.
5. `totalAsOf()` sur SQLite : bruit flottant possible sur résidus sub-centime (pilote de test seulement).
6. F-02/F-03 (dates UTC, gardes de période) non traités ici.
7. Certains clients afficheront un solde plus élevé qu'avant (l'ancien était sous-évalué) : à communiquer aux pilotes.
