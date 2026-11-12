<?php

namespace App\Services\Sandbox;

/**
 * Phase 6 — what Test Mode may do in the procurement / supplier purchasing
 * domain. Everything runs the SAME Production actions on the sandbox ledger
 * (routed models); there is no supplier communication anywhere in the domain,
 * so nothing outbound exists to simulate.
 *
 * Deliberately absent (still blocked in Test Mode): out-of-stock articles,
 * supplier directory and supplier catalogue maintenance (shared master data,
 * incl. supplier create/update), purchase-price import, MCP purchasing tools.
 */
final class SandboxProcurementRules
{
    /** Added to the Test permission lists of sales orders / POS carts and quotations. */
    public const PERMISSIONS = ['procurement.manage', 'procurement.receive', 'procurement.view', 'suppliers.view', 'pos.access'];

    public const ROUTES = [
        // Requirements, purchase orders, receiving.
        'procurement.index', 'procurement.availability', 'procurement.cancel', 'procurement.order',
        'procurement.receive', 'procurement.supplier',
        'procurement.purchase-orders.index', 'procurement.purchase-orders.show',
        'procurement.purchase-orders.cancel', 'procurement.purchase-orders.lines.receive',
        'sales.orders.procurements.store', 'sales.orders.purchase-orders.store',
        // Supplier sourcing (availability requests) from quotations, POS carts and the requests page.
        'procurement.supplier-requests.index', 'procurement.supplier-requests.record', 'procurement.supplier-requests.cancel',
        'quotations.sourcing.supplier-options', 'quotations.sourcing.supplier-recommendations',
        'quotations.sourcing.store', 'quotations.sourcing.record', 'quotations.sourcing.cancel',
        'pos.drafts.sourcing.supplier-options', 'pos.drafts.sourcing.supplier-recommendations',
        'pos.drafts.sourcing.store', 'pos.drafts.sourcing.record', 'pos.drafts.sourcing.cancel',
    ];
}
