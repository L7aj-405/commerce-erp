<?php

namespace App\Services\Sandbox;

use Closure;
use Illuminate\Database\Connection;
use LogicException;

/**
 * Fail-closed safety net under the routed inventory and finance models: while a Test
 * context is active, ANY SQL statement (read or write, Eloquent or raw query
 * builder) that names a Production inventory ledger table is refused before it
 * reaches the database. Raw queries that forgot to route to the `sandbox_`
 * tables therefore fail loudly instead of reading or changing Production stock.
 *
 * The only sanctioned exception is the one-time initialization snapshot, which
 * reads Production balances inside bypass().
 */
final class SandboxInventoryGuard
{
    public const PRODUCTION_TABLES = [
        'inventory_balances', 'inventory_movements', 'inventory_reservations',
        'sales_order_inventory_allocations', 'stock_transfers', 'stock_transfer_lines', 'stock_transfer_sequences',
        // Phase 5 — finance transaction ledger.
        'payments', 'payment_allocations', 'payment_refunds', 'payment_sequences', 'payment_refund_sequences',
        'commission_entries', 'finance_expected_collections', 'finance_expected_collection_matches',
    ];

    /**
     * Phase 4/5 Test Mode route allowlist. Deliberately absent: transfer
     * requests (shipping/receiving workflow), opening stock, warehouse
     * management, fulfillment, returns / exchanges, procurement, financial
     * account management and every finance report page (Production-only).
     */
    public const ROUTES = [
        'inventory.stock.index', 'inventory.movements.index', 'inventory.adjustments.store',
        'inventory.reservations.store', 'inventory.reservations.release', 'inventory.reservations.consume',
        'inventory.transfers.index', 'inventory.transfers.create', 'inventory.transfers.store', 'inventory.transfers.show',
        'sales.orders.confirm',
        // Phase 5 — Test payments and finance (simulated, Test ledger only).
        'pos.sales.store', 'sales.orders.payments.store', 'sales.orders.cancel',
        'payments.index', 'payments.show', 'payments.reverse',
        'finance.monthly-situation.expected-collections.store', 'finance.monthly-situation.expected-collections.update',
        'finance.monthly-situation.expected-collections.cancel', 'finance.monthly-situation.expected-collections.match',
    ];

    private static int $bypass = 0;

    /** @var \WeakMap<Connection, true>|null */
    private static ?\WeakMap $registered = null;

    public static function register(Connection $connection): void
    {
        self::$registered ??= new \WeakMap;
        if (isset(self::$registered[$connection])) {
            return;
        }
        self::$registered[$connection] = true;
        $pattern = '/(?<![a-z0-9_])('.implode('|', self::PRODUCTION_TABLES).')(?![a-z0-9_])/i';
        $connection->beforeExecuting(function (string $query) use ($pattern) {
            if (self::$bypass > 0 || ! app()->bound(SandboxContext::class) || ! app(SandboxContext::class)->isSandbox()) {
                return;
            }
            if (preg_match($pattern, $query, $match)) {
                throw new LogicException("Production inventory table [{$match[1]}] accessed in Test Mode.");
            }
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function bypass(Closure $callback): mixed
    {
        self::$bypass++;
        try {
            return $callback();
        } finally {
            self::$bypass--;
        }
    }
}
