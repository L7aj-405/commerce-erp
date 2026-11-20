# Phase 1 — Step 9 : audit de couverture (S-09 / INV-15)

Source : `PHASE_1_IMPLEMENTATION_PLAN.md` ligne 9 (« Audit de couverture (`AuditCoverageTest`) + vérification des actions sans audit direct », taille S, sans dépendance, critère « Liste d'actions ↔ événements »). Constat : `SECURITY_AND_TENANCY_REVIEW.md` S-09 (gravité basse) : `RemoveSalesOrderLineAction`, `ReverseSalesOrderCommissionsAction`, `ConsumeReservationAction` & co n'appellent pas `AuditLogger` directement ; INV-15 « Partiel ».

## Constat (vérifié dans le code)
Aucune mutation financière ou de stock n'était **sans** trace : toutes les actions sans appel direct délèguent à une couche qui audite, ou ne mutent rien. Le défaut était l'absence de **garde-fou** : une nouvelle action sans audit pouvait être ajoutée sans qu'aucun test ne le signale, et les délégations n'étaient pas vérifiées. Aucun code applicatif n'a donc été modifié.

| Action | Où l'audit est produit |
|---|---|
| `ReverseSalesOrderCommissionsAction`, `CreateReturnCommissionReversalsAction` | `AppendCommissionReversalAction` → `commission.entry_reversed` (un événement par écriture ajoutée) |
| 10 actions de cycle de vie d'expédition (`Cancel`, `Ready`, `Close`, `Delivered`, `HandToCarrier`, `InTransit`, `StartPreparation`, `Attach/RemoveProof`, `UpdateShipmentDetails`) | `FulfillmentEngine::record` → `fulfillment.*` |
| `FulfillSalesOrderAction` | `FulfillmentEngine` → `sales_order.fulfilled` |
| `Create/Consume/ReleaseReservationAction` | `InventoryReservationManager` → `inventory.reservation.*` |
| `ManualStockAdjustmentAction` | `AdjustInventoryAction` → `inventory.adjusted` |
| `RecordSalesOrderPaymentsAction` | `RecordPaymentAction` → `payment.posted` (un par paiement) |
| `RemoveSalesOrderLineAction` | brouillon uniquement (aucun effet financier avant confirmation) ; pendant une correction contrôlée, instantanés avant/après + `sales_order.correction_started/completed` portent l'historique |
| `SnapshotSalesOrderLineMarginsAction`, `RecordWooCommerceStockTaskAction` | exécutées dans la transaction déjà auditée (`sales_order.confirmed` / `sales_order.fulfilled`) |
| `StartInvoiceCorrectionAction`, `RecordSupplierAvailabilityAction` | retirées : refusent sans rien écrire |
| `Preview/StageProductCostImportAction` | mise en attente sans effet ; la confirmation est auditée |
| `NotifyDueFollowUpsAction` | notifications uniquement (historique : `user_notifications`) |
| 12 traits `Concerns/*` | aucun point d'entrée |

## Livrables (tests uniquement)
- `tests/Unit/Architecture/AuditCoverageTest.php` (3 tests, statique, sans base) : toute action de `app/Actions` sans référence réelle à `AuditLogger` doit figurer dans la liste revue ; chaque entrée est vérifiée contre le code (la couche déléguée contient bien l'événement attendu) ; une entrée périmée fait échouer le test. Vérifié par mutation : une nouvelle action sans audit fait échouer le test.
- Preuves d'exécution ajoutées : `CommissionLedgerTest` (2 événements `commission.entry_reversed` après retour partiel/complet, puis après correction + annulation) et `FulfillmentTest` (`fulfillment.cancelled`). Les événements de réservation, de paiement et de correction étaient déjà couverts.

## Notes
- `CommissionLedgerTest.php` et `FulfillmentTest.php` ont été reformatés par Pint (porte CI « Pint sur fichiers modifiés ») : le bruit de diff est mécanique ; les seuls ajouts de fond sont les 3 assertions ci-dessus.
- Décision à confirmer : ne pas auditer la suppression de ligne d'un brouillon ordinaire (bruit élevé sur les paniers POS, aucun effet financier).
- Aucune migration, route, UI.
