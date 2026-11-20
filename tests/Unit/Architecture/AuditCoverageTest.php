<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Audit-coverage architecture test (Phase 1 / Step 9; findings S-09 and INV-15).
 *
 * INV-15: every business effect is traced (who, what, before/after). Most Actions call AuditLogger directly.
 * The Actions that do NOT must be proven covered some other way, and a NEW Action without any audit call must
 * not slip in unnoticed. Static (source) test, no database:
 *
 *  1. every app/Actions file with no direct audit reference is listed below with a reviewed reason;
 *  2. each listed entry is verified against the code (the delegated layer really records the expected event);
 *  3. a stale entry (the file gained a direct audit, was renamed or deleted) fails, so the list only shrinks.
 *
 * Behavioral proof that the delegated layers emit their events at runtime lives next to the scenarios that
 * exercise them (CommissionLedgerTest, FulfillmentTest, InventoryReservationTest, ...).
 */
final class AuditCoverageTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../';

    /** kind: trait = helper, no entry point; delegate = audited by a callee; retired = refuses, mutates nothing; derived = covered by the audited event of its only caller; staging = no live effect, its confirm step audits. */
    private function reviewed(): array
    {
        $engine = 'app/Services/Fulfillment/FulfillmentEngine.php';
        $reversal = 'app/Actions/Commissions/AppendCommissionReversalAction.php';
        $reservations = 'app/Services/InventoryReservationManager.php';
        $confirm = 'app/Actions/Sales/ConfirmSalesOrderAction.php';

        $fulfillment = fn (string $event) => ['kind' => 'delegate', 'action' => ['engine->record(\''.$event.'\''], 'owner' => $engine, 'owner_has' => ['audit->record($event']];
        $traits = [
            'Concerns/MatchesActorTenant', 'Documents/Concerns/AuthorizesDocumentAction', 'Documents/Concerns/MutatesInvoiceCorrection',
            'Inventory/Concerns/AuthorizesInventoryAction', 'Inventory/Concerns/SnapshotsTransferDriver', 'Inventory/Concerns/ValidatesTransferRequestLines',
            'Payments/Concerns/AuthorizesPaymentAction', 'Pos/Concerns/MutatesPosCart', 'Procurement/Concerns/AuthorizesProcurementAction',
            'Quotations/Concerns/AuthorizesQuotationAction', 'Quotations/Concerns/MutatesQuotation', 'Sales/Concerns/AuthorizesSalesAction',
        ];
        $entries = [];
        foreach ($traits as $trait) {
            $entries[$trait] = ['kind' => 'trait'];
        }

        return $entries + [
            // Commission reversals: audited per appended entry by the shared append action.
            'Commissions/CreateReturnCommissionReversalsAction' => ['kind' => 'delegate', 'action' => ['reversals->execute('], 'owner' => $reversal, 'owner_has' => ["'commission.entry_reversed'"]],
            'Commissions/ReverseSalesOrderCommissionsAction' => ['kind' => 'delegate', 'action' => ['reversals->execute('], 'owner' => $reversal, 'owner_has' => ["'commission.entry_reversed'"]],
            // Fulfillment lifecycle: every transition records its canonical event through FulfillmentEngine::record.
            'Fulfillment/AttachFulfillmentProofAction' => $fulfillment('fulfillment.proof_attached'),
            'Fulfillment/CancelFulfillmentAction' => $fulfillment('fulfillment.cancelled'),
            'Fulfillment/CloseFulfillmentAction' => $fulfillment('fulfillment.closed'),
            'Fulfillment/HandFulfillmentToCarrierAction' => $fulfillment('fulfillment.handed_to_carrier'),
            'Fulfillment/MarkFulfillmentInTransitAction' => $fulfillment('fulfillment.in_transit'),
            'Fulfillment/MarkFulfillmentReadyAction' => $fulfillment('fulfillment.ready'),
            'Fulfillment/RemoveFulfillmentProofAction' => $fulfillment('fulfillment.proof_removed'),
            'Fulfillment/StartFulfillmentPreparationAction' => $fulfillment('fulfillment.preparation_started'),
            'Fulfillment/MarkFulfillmentDeliveredAction' => $fulfillment('fulfillment.delivered'),
            'Fulfillment/UpdateFulfillmentShipmentDetailsAction' => $fulfillment('fulfillment.tracking_updated'),
            // Manual stock adjustment: delegates the movement + balance change to AdjustInventoryAction (audited, same transaction).
            'Inventory/ManualStockAdjustmentAction' => ['kind' => 'delegate', 'action' => ['adjust->execute('], 'owner' => 'app/Actions/Inventory/AdjustInventoryAction.php', 'owner_has' => ["'inventory.adjusted'"]],
            // Follow-up reminders: notification delivery only (no financial or stock effect); user_notifications is the delivery history.
            'FollowUps/NotifyDueFollowUpsAction' => ['kind' => 'notification', 'action' => ['No audit', 'NotificationPublisher']],
            'Sales/FulfillSalesOrderAction' => ['kind' => 'delegate', 'action' => ['engine->'], 'owner' => $engine, 'owner_has' => ["'sales_order.fulfilled'"]],
            // Reservations: audited by InventoryReservationManager (same transaction as the stock change).
            'Inventory/CreateReservationAction' => ['kind' => 'delegate', 'action' => ['manager->'], 'owner' => $reservations, 'owner_has' => ["'inventory.reservation.created'"]],
            'Inventory/ConsumeReservationAction' => ['kind' => 'delegate', 'action' => ['manager->'], 'owner' => $reservations, 'owner_has' => ["'inventory.reservation.consumed'"]],
            'Inventory/ReleaseReservationAction' => ['kind' => 'delegate', 'action' => ['manager->'], 'owner' => $reservations, 'owner_has' => ["'inventory.reservation.released'"]],
            // Split payments: one RecordPaymentAction call (and audit event) per payment.
            'Payments/RecordSalesOrderPaymentsAction' => ['kind' => 'delegate', 'action' => ['recordPayment->execute('], 'owner' => 'app/Actions/Payments/RecordPaymentAction.php', 'owner_has' => ["'payment.posted'"]],
            // Draft-only line removal: no financial effect until confirmation. During a controlled correction the
            // full before/after snapshot and the correction events carry the history (line-level diff derivable).
            'Sales/RemoveSalesOrderLineAction' => ['kind' => 'derived', 'action' => ['isCommerciallyEditable()'], 'owner' => $confirm, 'owner_has' => ["'sales_order.correction_completed'", 'after_snapshot', "'sales_order.confirmed'"]],
            // Margin snapshot taken inside the audited confirmation transaction.
            'Sales/SnapshotSalesOrderLineMarginsAction' => ['kind' => 'derived', 'action' => [], 'owner' => $confirm, 'owner_has' => ['SnapshotSalesOrderLineMarginsAction', "'sales_order.confirmed'"]],
            // WooCommerce stock outbox row: idempotent queue entry created inside the audited fulfillment.
            'WooCommerce/RecordWooCommerceStockTaskAction' => ['kind' => 'derived', 'action' => [], 'owner' => $engine, 'owner_has' => ['RecordWooCommerceStockTaskAction', "'sales_order.fulfilled'"]],
            // Retired boundaries: fail closed, mutate nothing.
            'Documents/StartInvoiceCorrectionAction' => ['kind' => 'retired'],
            'Procurement/RecordSupplierAvailabilityAction' => ['kind' => 'retired'],
            // Staging / preview: no live catalog effect; the confirm step is audited.
            'Catalog/PreviewProductImportAction' => ['kind' => 'staging', 'owner' => 'app/Actions/Catalog/ConfirmProductImportAction.php', 'owner_has' => ["'catalog.products_imported'"]],
            'Catalog/StageProductCostImportAction' => ['kind' => 'staging', 'owner' => 'app/Actions/Catalog/ConfirmProductCostImportAction.php', 'owner_has' => ["'product_cost.import_confirmed'"]],
        ];
    }

    /** @return array<string, string> relative key (without .php) => source */
    private function unauditedActions(): array
    {
        $found = [];
        $base = realpath(self::ROOT.'app/Actions');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (! preg_match('/AuditLogger|\$\w*audit\w*\s*->\s*record\(|->audit->record\(/i', $source)) {
                $key = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1, -4));
                $found[$key] = $source;
            }
        }
        ksort($found);

        return $found;
    }

    private function source(string $relative): string
    {
        $path = self::ROOT.$relative;
        $this->assertFileExists($path, "Reviewed audit owner {$relative} is missing.");

        return (string) file_get_contents($path);
    }

    public function test_every_action_without_a_direct_audit_call_is_reviewed(): void
    {
        $unreviewed = array_values(array_diff(array_keys($this->unauditedActions()), array_keys($this->reviewed())));

        $this->assertSame([], $unreviewed, "New Action(s) with no audit reference. Add an AuditLogger event (INV-15) or, if the audit truly happens in a callee, add a verified entry to AuditCoverageTest::reviewed():\n - ".implode("\n - ", $unreviewed));
    }

    public function test_the_reviewed_list_has_no_stale_entries(): void
    {
        $unaudited = array_keys($this->unauditedActions());
        $stale = array_values(array_diff(array_keys($this->reviewed()), $unaudited));

        $this->assertSame([], $stale, "Reviewed entries whose Action now has a direct audit call (or no longer exists) — remove them:\n - ".implode("\n - ", $stale));
    }

    public function test_each_reviewed_entry_is_backed_by_the_code(): void
    {
        $actions = $this->unauditedActions();
        $failures = [];
        foreach ($this->reviewed() as $key => $entry) {
            $source = $actions[$key] ?? null;
            if ($source === null) {
                continue; // reported by the stale test
            }
            if ($entry['kind'] === 'trait' && ! preg_match('/^\s*trait\s/m', $source)) {
                $failures[] = "{$key}: expected a trait";
            }
            if ($entry['kind'] === 'retired' && (! str_contains($source, 'ValidationException::withMessages') || preg_match('/->(save|delete|update|forceFill)\(|::create\(|DB::table/', $source))) {
                $failures[] = "{$key}: a retired Action must only refuse (no persistence calls)";
            }
            foreach ($entry['action'] ?? [] as $needle) {
                if (! str_contains($source, $needle)) {
                    $failures[] = "{$key}: expected `{$needle}` in the Action";
                }
            }
            if (isset($entry['owner'])) {
                $owner = $this->source($entry['owner']);
                foreach ($entry['owner_has'] as $needle) {
                    if (! str_contains($owner, $needle)) {
                        $failures[] = "{$key}: {$entry['owner']} no longer contains `{$needle}`";
                    }
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
