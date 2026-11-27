# Phase 1 — Étape 5 : intégrité des échanges clients

Branche `fix/phase-1-step-5-exchange-integrity` (base `origin/staging` @ `33c044f`). Cible : staging uniquement.

## 1. Périmètre d'origine
`PHASE_1_IMPLEMENTATION_PLAN.md` ligne 5 : « Atomicité du règlement d'échange (transaction englobante) », findings **F-07 / S-05** (gravité moyenne), test de panne injectée exigé. L'audit de cette étape a montré que le même motif (« sous-action validée dans sa propre transaction, puis mise à jour de l'échange dans une seconde ») existait dans **les cinq** actions de transition ; le correctif a donc été appliqué à chacune, sans refonte du workflow ni de la politique comptable.

## 2. Défauts prouvés (reproduits sur l'ancien code) et risques
| # | Constat | Preuve |
|---|---|---|
| D1 (F-07) | Règlement non atomique : paiements validés puis lien à l'échange dans une 2ᵉ transaction | test de panne injectée : 2 paiements au lieu de 1 après échec |
| D2 | **Sur-encaissement concurrent** : le « reste dû » est calculé avant tout verrou ; N sessions qui paient chacune ≤ reste passent toutes | MySQL, 4 sessions × 200 sur une différence de 300 : **800 encaissés** |
| D3 | **Sur-remboursement concurrent** : `RefundCustomerReturnAction` ne plafonne qu'au total du retour, la cible de l'échange (retourné − nouveau) n'est vérifiée qu'avant verrou | MySQL, 4 × 200 sur une cible de 300 : **800 remboursés** |
| D4 | Réception, remise, annulation : sous-action commitée puis état de l'échange mis à jour séparément (stock restocké / avoir émis / articles remis sans échange à jour après une panne) | tests de panne injectée (4 échecs) |
| D5 | Annulation sans re-contrôle d'état sous verrou : une annulation « périmée » écrasait un échange livré ; une remise « périmée » ressuscitait un échange annulé | tests d'état périmé |
| D6 | Rejeu d'un paiement/remboursement déjà abouti (même `client_operation_id`) : erreur de validation au lieu d'un rejeu idempotent. Réception/remise répétées restent **refusées** (ValidationException, comportement existant verrouillé par `SandboxReturnsTest`) et n'ont aucun effet de stock | tests d'idempotence |
Risques non prouvés / hors périmètre : voir §8.

## 3. Avant / après
Chaque action prend désormais **une seule transaction** : verrou de l'échange (`organization_id` + `store_id`) → relecture de l'état sous verrou → sous-action (savepoint imbriqué) → mise à jour de l'échange + audit. Un échec à n'importe quel point annule tout. Un rejeu d'un paiement/remboursement (même opération) renvoie l'état courant sans rien reposter (le contrôle de charge utile reste fait par `RecordSalesOrderPaymentsAction` / `RefundCustomerReturnAction`).

## 4. Fichiers
`app/Actions/Exchanges/{Receive,Fulfill,SettlePayment,SettleRefund,Cancel}CustomerExchange*Action.php` (la création est inchangée : déjà atomique et idempotente) ; tests `tests/Feature/Exchanges/ExchangeIntegrityTest.php` (17) ; `tests/Concurrency/exchange_settlement_race.php`. Aucun changement de migration, de route, de permission, de TVA, de facturation ou d'avoir.

## 5. Invariants
- Σ paiements postés liés à l'échange ≤ différence ; Σ remboursements postés du retour ≤ −différence (cible), vérifiés sous verrou.
- Un échange n'est livré qu'une fois (1 addendum, 1 consommation de stock) ; jamais « annulé » ET livré.
- Une réception restocke et émet l'avoir une seule fois ; non vendable (`damaged`) ne restocke pas (inchangé).
- Aucun paiement orphelin ; aucune écriture partielle après exception.
- Mois clos : refus avant toute écriture (F-03 inchangé), vérifié par test.
- Montants en `Decimal` (bcmath), aucun `(float)`.

## 6. Concurrence
Ordre des verrous : **échange → retour → commande → variante → solde/paiement/allocation**. `CreateCustomerExchangeAction` verrouille la commande puis *insère* l'échange (aucune attente sur une ligne d'échange existante) : pas de cycle. Script `tests/Concurrency/exchange_settlement_race.php` (processus PHP séparés, barrière de départ) : A remise ×N, E réception ×N, B paiement ×N, C remboursement ×N, D annulation ‖ remise. Ancien code : B et C échouent (800 vs 300). Nouveau code : tous PASS, compteur de deadlocks InnoDB +0.

## 7. Résultats de tests (exacts)
| Commande | Résultat |
|---|---|
| SQLite `tests/Feature/Exchanges` | 13/13 existants (avant ajout) ; `ExchangeIntegrityTest` **17/17, 97 assertions** ; sur l'ancien code 10 des 17 échouent (7 sont des garde-fous déjà vrais) |
| MySQL 8.4.3 `exchange_settlement_race.php --rounds=2 --rivals=6` | **PASSED**, 0 échec, deadlocks +0 (même script sur l'ancien code : FAILED B, C) |
| SQLite `tests/Unit/Architecture` | 10/10 (506 assertions) |
| Pint `--test` (fichiers modifiés) | exit 0 |
| MySQL 8.4.3 phpunit (Exchanges, Returns, Payments, Finance, Pos, Tenancy, Sandbox, Unit) | 734 tests : 726 OK, 6 ignorés, 2 échecs : `MoroccoLegalTimeTest` (tzdb local obsolète, environnement) et `SandboxReturnsTest` (**régression introduite par un rejeu idempotent de remise, corrigée** : réception/remise répétées de nouveau refusées). Après correction : Exchanges + SandboxReturnsTest **32/32** (304 assertions) |
| `scripts/check-timezone-runtime.php` | **ÉCHEC local** : PHP local sans `timezonedb-2026.6` (environnement, pas le code) ; CI épingle l'extension |
Non exécuté : suite complète SQLite, navigateur.

## 8. Décisions comptables ouvertes
1. Une remise livrée avec différence nulle ou un échange complété ne génère pas de nouvelle facture automatiquement (comportement existant, inchangé) : politique de refacturation à confirmer.
2. Remboursement : l'opérateur choisit le paiement d'origine ; aucune règle de répartition automatique (inchangé).
3. Annulation d'un échange après réception conserve le retour reçu et son avoir (inchangé, voulu).
4. Prix de remplacement figé à la création (affichage) mais l'addendum recalcule au prix courant : l'écart éventuel est porté par `difference_amount` à la remise ; décision sur le gel du prix.

## 9. UAT Cloud Browser (staging, sans mutation persistante hors données de test)
1. Échange même prix : réception → remise → complété, aucun paiement/remboursement.
2. Plus cher : régler la différence en 2 paiements ; double clic sur « Enregistrer » → un seul paiement.
3. Moins cher : 2 remboursements partiels jusqu'à la cible ; un 3ᵉ est refusé.
4. Deux onglets : remettre dans les deux → un seul addendum, stock −1.
5. Onglet A annule pendant que B remet → un seul état final cohérent.
6. Retour non vendable : stock ancien inchangé ; stock insuffisant à la remise : message, rien modifié.
7. Mois clos : réception/règlement refusés sans écriture. Utilisateur sans `sales_exchanges.process` : 403.
8. Régression : vente POS, correction de commande (PR #32) toujours sans survente.
