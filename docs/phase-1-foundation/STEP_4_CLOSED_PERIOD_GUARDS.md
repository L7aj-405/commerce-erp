# Phase 1 / Step 4 — F-03 : gardes de période financière clôturée

> Branche `fix/phase-1-step-4-closed-period-guards`, basée sur `origin/staging` @ `3ff8a53` (PR #30). PR vers **`staging` uniquement**.
> Périmètre : émission de facture, création/émission d'avoir, solde d'ouverture. Aucune règle comptable nouvelle : la politique existante de `FinanceClosedPeriodGuard` (Sprint 0 / WS2) est appliquée telle quelle.

## 1. Comportement existant
- Un mois clôturé dans « Situation fin de mois » (`finance_month_reviews.status = closed`) refusait déjà : paiements clients et leur annulation, remboursements, paiements sortants, obligations, échéanciers fournisseurs, instruments de paiement (`FinanceClosedPeriodGuard`, verrou **partagé** sur la ligne du mois ; la clôture prend le verrou **exclusif** sur la même ligne).
- Chemin de correction : réouverture (propriétaire ou `finance.periods.reopen`, motif obligatoire, auditée) → correction → nouvelle clôture. Pas de contournement silencieux.
- **Non protégés** (constat F-03, déjà signalé au Step 3 §11) : émission de facture (`IssueInvoiceAction`), émission d'avoir (`ReceiveCustomerReturnAction::issueCreditNote`), création de brouillon d'avoir (`CreateCustomerReturnAction::createCreditNoteDrafts`), solde d'ouverture (`SetFinancialAccountOpeningPositionAction`).

## 2. Défaut et risque
Une facture datée dans un mois clôturé (rétro-datation autorisée par `invoices.backdate`) pouvait être émise après la clôture : le chiffre d'affaires facturé du mois (`FinanceMonthlyReportService`, journaux, créances) changeait sans réouverture ni audit de réouverture. Idem pour un solde d'ouverture daté dans un mois clos (position de trésorerie) et pour un avoir émis dans un mois marqué clos.

## 3. Implémentation (4 actions, ≈ 40 lignes)
| Action | Garde | Date testée | Champ d'erreur |
|---|---|---|---|
| `IssueInvoiceAction` | dans la transaction, **après** les verrous facture → commande → paiements, **avant** le verrou de la famille de numéros | `invoice_date` (+ `invoice_date` de la facture d'origine si c'est une correction, qui la remplace) ; ignorée pour les factures Test Mode (`sandbox_id`) | `invoice_date` |
| `ReceiveCustomerReturnAction` | au début de la transaction (avant tout mouvement de stock) et à nouveau dans `issueCreditNote` | `BusinessTime::today(org)` = date de l'avoir | `credit_note_date` |
| `CreateCustomerReturnAction` | avant la création de chaque brouillon d'avoir | `BusinessTime::today(org)` | `credit_note_date` |
| `SetFinancialAccountOpeningPositionAction` | après le verrou du compte et de la position | nouvelle `effective_date` **et** date actuelle de la position (édition) | `effective_date` |

Les dates sont des dates métier de l'organisation (`BusinessTime`, fuseau de l'organisation) ; aucun `now()` UTC, aucun décalage codé en dur ; aucune date n'est modifiée silencieusement. Message : « La période … est clôturée : … réouvrez le mois dans « Situation fin de mois » … ». Les échanges et le MCP passent par les mêmes actions (pas de contournement trouvé : aucun import, job ou `DB::table` n'écrit `invoices`/`credit_notes`/positions d'ouverture).

## 4. Hypothèses de politique (à confirmer)
1. **Date de la facture = `invoice_date`** (elle fixe l'année de numérotation et tous les rapports), pas `issued_at`.
2. **Avoir daté du jour** (comportement existant) : refusé seulement si le mois courant est clos (cas réel rare : on ne peut pas clore un mois non terminé ; couvert par simulation).
3. **Correction de facture** : émettre la correction fait passer l'originale en `Superseded`, donc la retire des chiffres de **son** mois : ce mois doit être ouvert aussi. (Les corrections « facture seule » sont désactivées côté domaine/HTTP ; la garde protège le chemin hérité.)
4. **Réception d'un retour** avec avoir dans un mois clos : **toute la réception est refusée** (stock inclus), car l'avoir est émis dans la même transaction. Alternative (réception physique puis avoir différé) = décision produit, non inventée ici.
5. Test Mode : jamais bloqué par les clôtures de production (politique existante du garde).
6. Brouillons de facture (création/modification de date) **non** gardés : aucun effet comptable ; l'erreur apparaît à l'émission.

## 5. Concurrence
- Même mécanisme que les paiements : verrou partagé sur `finance_month_reviews` dans la transaction de l'opération ; la clôture prend le verrou exclusif (ou insère la ligne sous la clé unique `(organization_id, period)`, bloquée par le gap lock). Une émission et une clôture du même mois se sérialisent : soit l'émission est validée avant la clôture, soit elle voit le mois clos et est refusée.
- **Ordre des verrous préservé** : facture → commande → allocations/paiements → *(mois, partagé)* → famille de numéros. La clôture ne prend aucun autre verrou : pas d'interblocage possible via cette ligne.
- Test MySQL réel : `tests/Concurrency/closed_period_race.php` (ajouté à la CI `mysql-concurrency`).

## 6. Tests ajoutés
- `tests/Feature/Finance/ClosedPeriodDocumentGuardsTest.php` — 14 tests : émission ouverte/clôturée (clôture réelle puis réouverture), pas d'écriture partielle (brouillon intact, numéro non consommé, pas d'audit, montants inchangés), rétro-datation, fuseaux (Casablanca vs New York à 2025-12-31 23:30 UTC), isolation d'organisation, Test Mode, permissions (refus d'autorisation avant la période), avoir (création, réception atomique, stock intact, retour sans facture non bloqué), solde d'ouverture (nouvelle date, date actuelle, permission), totaux inchangés. **Sans le correctif : 7 tests échouent ; avec : 14/14.**
- `tests/Concurrency/closed_period_race.php` — A : un verrou exclusif de clôture est tenu, N émetteurs démarrent puis sont tous refusés (brouillons intacts) ; B : course libre émission ↔ `ManageFinanceMonthReviewAction::close()` réelle, sorties cohérentes, numéros consécutifs, émission tardive refusée, **0 interblocage**. Les deux ordres (tout émis avant / tout refusé) ont été observés.

## 7. Preuves d'exécution (poste de développement, bases jetables)
| Contrôle | Résultat |
|---|---|
| Nouveaux tests `ClosedPeriodDocumentGuardsTest` (SQLite / MySQL 8.4.3) | 14/14 / 14/14 ; 7 échecs sans le correctif |
| Suite complète SQLite | 2347 tests : 2346 verts + 1 échec **préexistant d'environnement** (ci-dessous) ; la régression trouvée a été corrigée puis `FinanceMonthReviewExportTest` 6/6 |
| MySQL 8.4.3 critique (Finance, Payments, Documents, Sales, Pos, Tenancy, Sandbox, Returns, Exchanges, Mcp, Quotations, Unit) | 1207 tests, mêmes 2 constats ; après correction : fichiers Finance concernés 20/20 |
| Course MySQL `closed_period_race.php` | PASSED, 0 interblocage (plusieurs exécutions, 4 à 10 factures) |
| Gardes d'architecture | 10/10 |
| Pint sur les fichiers modifiés | passed |
| Garde d'exécution fuseau marocain (`scripts/check-timezone-runtime.php`) | **échoue sur ce poste** : PHP 8.4.12 avec tzdata 2025.2 (`MoroccoLegalTimeTest::test_runtime_timezone_database…` échoue pour la même raison). Préexistant, environnemental ; la CI épingle `timezonedb-2026.6` |
| TypeScript / build frontend | non concernés (aucun fichier front modifié) |

**Régression détectée et corrigée** : `FinanceMonthReviewExportTest::test_close_warnings_…` documentait l'ancien trou (un solde d'ouverture dans un mois clos passait). Il vérifie maintenant le refus, et conserve le contrôle de péremption (`stale`) via une écriture hors action. Aucun test n'a été affaibli pour passer.

## 8. Décisions comptables restantes
- Date et traitement d'un avoir lié à une facture d'un mois clos (l'avoir est daté du jour : confirmer que c'est la règle voulue), et réception physique d'un retour quand le mois est clos (D-F3).
- Faut-il aussi refuser tôt la **création/modification de date d'un brouillon** de facture dans un mois clos ?
- `FinanceMonthReviewService::changedAfterClose` ne surveille ni les factures ni les avoirs ; le **snapshot** de clôture n'inclut pas la facturation ; il est calculé **avant** le verrou de clôture (existant, non modifié ici).
- Gardes absentes ailleurs : bons de livraison, apposition de cachet/e-mail de facture, restauration de sauvegarde, remise à zéro sélective (hors périmètre Step 4).
- Politique de dérogation (rôle autorisé à émettre dans un mois clos) : **non implémentée** ; la seule voie est la réouverture auditée.

## 9. Cas de recette staging (UAT)
1. Clore septembre ; créer une facture brouillon datée du 20/09 (compte avec `invoices.backdate`), payée ; **Émettre** → refus « Septembre 2026 est clôturé… ». Vérifier : brouillon, pas de numéro, aucun audit `invoice.issued`.
2. Rouvrir septembre (motif) ; émettre → facture `N/2026` ; clore à nouveau.
3. Facture datée d'octobre (ouvert) : émission OK alors que septembre est clos.
4. Solde d'ouverture daté 01/09 sur un compte : refus ; daté du mois ouvert : OK.
5. Retour client sur une facture dans un mois courant ouvert : avoir `AV-…` émis ; stock mis à jour.
6. Organisation B (septembre ouvert) : mêmes opérations non bloquées.
7. Test Mode : facture rétro-datée en septembre clos : émise (TEST-FAC-…).
8. Utilisateur sans `invoices.issue` : 403/404, pas le message de période.

## 10. Rappel de promotion
**Phase 1 ne peut pas être promue vers `main` sans traiter l'historique du revert de la PR #25** : `main` contient un revert de la fusion de la PR #24 (Step 1 + F-01). Une promotion `staging → main` ne rétablira pas automatiquement ces changements ; la release candidate doit d'abord annuler le revert (revert-of-revert) ou restaurer Step 1 + F-01 exactement une fois, puis vérifier l'arbre attendu.
