<?php

namespace App\Services\Sandbox;

/**
 * Phase 8 — what Test Mode may do in the after-sales domain (customer returns,
 * exchanges, return refunds and return-based credit notes). The SAME Production
 * actions run against Test records: returns / exchanges are sandbox-owned roots
 * (sandbox_id), restocking and replacement stock use the Test inventory ledger,
 * refunds and exchange settlements use the Test finance ledger, credit notes
 * use TEST-AV numbering and commission reversals hit Test commission entries.
 *
 * Test references come from sandbox_document_sequences (TEST-RET-, TEST-ECH-,
 * TEST-AV-, TEST-REF-); no Production sequence is ever read or written.
 *
 * Deliberately absent (still blocked): the return-policy settings page,
 * WooCommerce / integrations, finance reports and the MCP return tools.
 */
final class SandboxReturnsRules
{
    public const ROUTES = [
        // Returns.
        'sales.returns.index', 'sales.returns.search', 'sales.returns.show', 'sales.returns.receive',
        'sales.returns.cancel', 'sales.returns.refunds.store',
        'sales.orders.returns.create', 'sales.orders.returns.store',
        // Exchanges.
        'sales.orders.exchanges.create', 'sales.orders.exchanges.search', 'sales.orders.exchanges.store',
        'sales.exchanges.show', 'sales.exchanges.receive', 'sales.exchanges.fulfill',
        'sales.exchanges.payments.store', 'sales.exchanges.refunds.store', 'sales.exchanges.cancel',
    ];
}
