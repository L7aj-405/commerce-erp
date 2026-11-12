<?php

namespace App\Services\Sandbox;

/**
 * Phase 7 — what Test Mode may do in the fulfillment / carrier / shipment /
 * customer hand-over domain. The SAME Production actions and engine run on the
 * sandbox fulfillment ledger (routed models) and the Phase 4 Test inventory
 * ledger: stock leaves Test inventory exactly once, when a shipment is handed
 * over (carrier or direct), through the same single consume step.
 *
 * Outbound is simulated: customer notifications are recorded as "NOTIFICATION
 * TEST — NON ENVOYÉE" and never queued or mailed. The Production workflow has no
 * carrier API, label generation or internal shipping number (carriers are
 * shared transport contacts; tracking is typed by the operator), so there is
 * nothing external to call and no TEST-SHP sequence to consume.
 *
 * Deliberately absent (still blocked in Test Mode): shipping proofs / file
 * attachments, "Partager au client" (e-mail / WhatsApp), shipment follow-up
 * outcomes, returns, exchanges and everything that depends on them.
 */
final class SandboxFulfillmentRules
{
    /** Added to the Test permission lists of sales orders. */
    public const PERMISSIONS = ['sales_orders.fulfill', 'fulfillment.view', 'fulfillment.dispatch', 'fulfillment.manage', 'fulfillment.mark_delivered'];

    public const ROUTES = [
        'sales.orders.fulfill',
        'sales.orders.fulfillments.carrier-options', 'sales.orders.fulfillments.preparation',
        'sales.orders.fulfillments.hand-to-carrier', 'sales.orders.fulfillments.ready',
        'sales.orders.fulfillments.hand-ready-to-carrier', 'sales.orders.fulfillments.in-transit',
        'sales.orders.fulfillments.delivered', 'sales.orders.fulfillments.close',
        'sales.orders.fulfillments.cancel', 'sales.orders.fulfillments.update',
    ];
}
