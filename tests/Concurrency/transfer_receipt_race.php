<?php

/**
 * MySQL concurrency regression for the receipt of a customer transfer (incident TRQ-000030).
 *
 *   php tests/Concurrency/transfer_receipt_race.php [--rivals=4] [--rounds=3] [--database=commerce_erp_dev_test]
 *
 * Real actions, separate PHP processes (separate MySQL sessions), one start barrier. Each round: a POS order of 3
 * units (2 held at the Showroom, 1 at the depot), its customer transfer shipped.
 *
 * Scenario A - "double receipt": N sessions receive the same request (odd rounds with both holds released, the
 *   incident state; even rounds healthy). Exactly one wins, one stock transfer exists, the order holds 3 at the
 *   Showroom.
 *
 * Scenario B - "receipt vs rivals": both holds released, so the 2 Showroom units are free. The receipt races N
 *   rival POS orders for them. Either the receipt holds all 3 units, or it is refused and stays shipped. Never a
 *   partial state, never over-reserved.
 *
 * Pass also requires that InnoDB's deadlock counter did not move. Safety: local *_test / *_dev MySQL databases
 * only; every round creates its own organization. Requires a migrated database.
 */

use App\Actions\Inventory\OpeningStockAction;
use App\Actions\Inventory\PrepareTransferRequestAction;
use App\Actions\Inventory\ReceiveTransferRequestAction;
use App\Actions\Inventory\ShipTransferRequestAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Enums\SalesOrderSource;
use App\Models\InventoryReservation;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\TransferRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryReservationManager;
use App\Services\OrganizationCreator;
use App\Services\StoreCreator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
$options = getopt('', ['rivals::', 'rounds::', 'database::', 'child::', 'target::', 'user::', 'start::']);
$database = $options['database'] ?? 'commerce_erp_dev_test';

foreach ([
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
    'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'BROADCAST_CONNECTION' => 'null',
    'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection();
$host = (string) $connection->getConfig('host');
$actualDb = $connection->getDatabaseName();
if ($connection->getDriverName() !== 'mysql' || ! in_array($host, ['127.0.0.1', 'localhost'], true)
    || ! preg_match('/_(test|dev)$/', $actualDb)) {
    fwrite(STDERR, "Refusing to run against [{$connection->getDriverName()}://{$host}/{$actualDb}]: local *_test / *_dev MySQL databases only.\n");
    exit(2);
}

// ---------------------------------------------------------------- child
if (isset($options['child'])) {
    try {
        $user = User::query()->findOrFail((int) $options['user']);
        $target = $options['child'] === 'receive'
            ? TransferRequest::query()->withoutGlobalScopes()->findOrFail((int) $options['target'])
            : SalesOrder::query()->withoutGlobalScopes()->findOrFail((int) $options['target']);
        while (microtime(true) < (float) $options['start']) {
            usleep(100);
        }
        match ($options['child']) {
            'receive' => app(ReceiveTransferRequestAction::class)->execute($user, $target),
            'confirm' => app(ConfirmSalesOrderAction::class)->execute($user, $target),
        };
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'class' => class_basename($e), 'message' => Str::limit($e->getMessage().' '.json_encode(method_exists($e, 'errors') ? $e->errors() : ''), 300)]);
    }
    exit(0);
}

// ---------------------------------------------------------------- parent
$rivals = max(1, (int) ($options['rivals'] ?? 4));
$rounds = max(1, (int) ($options['rounds'] ?? 3));

$draft = function (User $owner, Organization $org, Warehouse $showroom, ProductVariant $variant, string $quantity): SalesOrder {
    $store = $org->stores()->firstOrFail();
    $order = app(CreateSalesOrderAction::class)->execute($owner, $org, $store,
        ['customer_id' => null, 'sale_date' => now()->toDateString(), 'currency_code' => 'MAD', 'notes' => null], SalesOrderSource::Pos);
    $order->pos_warehouse_id = $showroom->id;
    $order->pos_fulfillment_mode = 'pickup';
    $order->save();
    app(SaveSalesOrderLineAction::class)->execute($owner, $order->fresh(), ['line_type' => 'catalog', 'product_variant_id' => $variant->id,
        'warehouse_id' => $showroom->id, 'quantity' => $quantity, 'discount_type' => 'none', 'discount_value' => '0.0000']);

    return $order->fresh();
};

/** @return array{User, Organization, Warehouse, Warehouse, ProductVariant, SalesOrder, TransferRequest} */
$world = function (string $label, bool $released) use ($draft): array {
    $owner = new User;
    $owner->name = "Receipt race {$label}";
    $owner->email = 'rcpt-'.Str::lower(Str::random(12)).'@race.local';
    $owner->password = 'race-not-a-login';
    $owner->email_verified_at = now();
    $owner->save();
    $org = app(OrganizationCreator::class)->create($owner, "Receipt race {$label}");
    $store = app(StoreCreator::class)->create($owner, $org, 'Store', Str::upper(Str::random(8)));
    $owner->active_organization_id = $org->id;
    $owner->active_store_id = $store->id;
    $owner->save();
    // The Showroom is created first: its id is lower than the depot's, the deadlock-prone order.
    $warehouses = [];
    foreach (['MAIN' => 'Showroom', 'SP' => 'Depot'] as $code => $name) {
        $w = new Warehouse;
        $w->organization_id = $org->id;
        $w->name = $name;
        $w->code = $code;
        $w->status = 'active';
        $w->save();
        $warehouses[] = $w;
    }
    [$showroom, $depot] = $warehouses;
    $product = new Product;
    $product->organization_id = $org->id;
    $product->name = 'Mic stand';
    $product->status = 'active';
    $product->save();
    $variant = new ProductVariant;
    $variant->organization_id = $org->id;
    $variant->product_id = $product->id;
    $variant->sku = 'TKM-RACE';
    $variant->default_sale_price = $variant->regular_sale_price = $variant->unit_price_ht = '100.0000';
    $variant->status = 'active';
    $variant->save();
    app(OpeningStockAction::class)->execute($owner, $org, $showroom, $variant, '2.0000', 'race');
    app(OpeningStockAction::class)->execute($owner, $org, $depot, $variant, '28.0000', 'race');

    $order = $draft($owner, $org, $showroom, $variant, '3.0000');
    app(ConfirmSalesOrderAction::class)->execute($owner, $order);
    $request = TransferRequest::query()->withoutGlobalScopes()->where('sales_order_id', $order->id)->firstOrFail();
    app(PrepareTransferRequestAction::class)->execute($owner, $request->fresh());
    app(ShipTransferRequestAction::class)->execute($owner, $request->fresh());
    if ($released) {
        foreach (InventoryReservation::query()->withoutGlobalScopes()->where('organization_id', $org->id)->orderBy('id')->get() as $reservation) {
            app(InventoryReservationManager::class)->release($owner, $org, $reservation);
        }
    }

    return [$owner, $org, $showroom, $depot, $variant, $order, $request];
};

$launch = function (array $jobs, float $start) use ($database): array {
    $env = array_merge(getenv(), ['DB_DATABASE' => $database]);
    $procs = [];
    foreach ($jobs as $job) {
        $cmd = [PHP_BINARY, __FILE__, '--child='.$job['child'], '--target='.$job['target'], '--user='.$job['user'], '--start='.$start, '--database='.$database];
        $errFile = tempnam(sys_get_temp_dir(), 'tr');
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, $env);
        if (! is_resource($proc)) {
            throw new RuntimeException('Could not start child process.');
        }
        $procs[] = [$job, $proc, $pipes, $errFile];
    }
    $results = [];
    foreach ($procs as [$job, $proc, $pipes, $errFile]) {
        $out = stream_get_contents($pipes[1]);
        proc_close($proc);
        $err = (string) @file_get_contents($errFile);
        @unlink($errFile);
        $results[] = ['job' => $job, 'r' => json_decode(trim($out), true) ?? ['ok' => false, 'class' => 'ChildCrash', 'message' => Str::limit(trim($out.' '.$err), 300)]];
    }

    return $results;
};
$deadlocks = fn (): int => (int) DB::table('information_schema.INNODB_METRICS')->where('NAME', 'lock_deadlocks')->value('COUNT');
$unexpected = fn (array $results): array => array_values(array_map(
    fn (array $x) => 'unexpected outcome (deadlock / timeout / other): '.json_encode($x['r']),
    array_filter($results, fn (array $x) => ! $x['r']['ok'] && $x['r']['class'] !== 'ValidationException'),
));
$ledgerErrors = function (Organization $org): array {
    $errors = [];
    $onHand = 0.0;
    foreach (DB::table('inventory_balances')->where('organization_id', $org->id)->get() as $b) {
        $onHand += (float) $b->on_hand;
        $active = (float) DB::table('inventory_reservations')->where('organization_id', $org->id)->where('warehouse_id', $b->warehouse_id)
            ->where('product_variant_id', $b->product_variant_id)->where('status', 'active')->sum('quantity');
        if (abs((float) $b->reserved - $active) > 0.00005) {
            $errors[] = "reserved {$b->reserved} != active reservations {$active}";
        }
        if ((float) $b->reserved > (float) $b->on_hand + 0.00005 || (float) $b->reserved < 0) {
            $errors[] = "reserved {$b->reserved} outside [0, on_hand {$b->on_hand}]";
        }
    }
    abs($onHand - 30.0) < 0.00005 || $errors[] = "stock not conserved: {$onHand} on hand";

    return $errors;
};
$held = fn (int $orderId, int $warehouseId): float => (float) DB::table('sales_order_lines as l')
    ->join('sales_order_inventory_allocations as a', 'a.sales_order_line_id', '=', 'l.id')
    ->join('inventory_reservations as r', 'r.id', '=', 'a.inventory_reservation_id')
    ->where('l.sales_order_id', $orderId)->where('a.warehouse_id', $warehouseId)->where('r.status', 'active')->sum('r.quantity');

echo "Transfer-receipt race on mysql://{$host}/{$actualDb} - {$rounds} round(s), {$rivals} rivals\n";
$before = $deadlocks();
$failures = 0;
$report = function (string $label, array $errors) use (&$failures): void {
    printf("  %-48s %s\n", $label, $errors === [] ? 'PASS' : 'FAIL');
    foreach ($errors as $error) {
        echo "      x {$error}\n";
    }
    $failures += $errors === [] ? 0 : 1;
};

for ($round = 1; $round <= $rounds; $round++) {
    // --- Scenario A ---------------------------------------------------------------------------------------------
    [$owner, $org, $showroom, , , $order, $request] = $world("A{$round}", $round % 2 === 1);
    $results = $launch(array_fill(0, $rivals, ['child' => 'receive', 'target' => $request->id, 'user' => $owner->id]), microtime(true) + 4.0);
    $errors = [...$unexpected($results), ...$ledgerErrors($org)];
    $wins = count(array_filter($results, fn (array $x) => $x['r']['ok']));
    $wins === 1 || $errors[] = "expected exactly 1 receipt to win, got {$wins}";
    DB::table('stock_transfers')->where('organization_id', $org->id)->count() === 1 || $errors[] = 'expected exactly one stock transfer';
    $held($order->id, $showroom->id) === 3.0 || $errors[] = 'the order does not hold 3 units at the Showroom';
    $report("A#{$round} {$rivals} simultaneous receipts", $errors);

    // --- Scenario B ---------------------------------------------------------------------------------------------
    [$owner, $org, $showroom, , $variant, $order, $request] = $world("B{$round}", true);
    $jobs = [['child' => 'receive', 'target' => $request->id, 'user' => $owner->id]];
    for ($i = 0; $i < $rivals; $i++) {
        // 29 units: more than the depot's 28, so each rival must lock BOTH warehouses.
        $rival = $draft($owner, $org, $showroom, $variant, '29.0000');
        $jobs[] = ['child' => 'confirm', 'target' => $rival->id, 'user' => $owner->id];
    }
    $results = $launch($jobs, microtime(true) + 4.0);
    $errors = [...$unexpected($results), ...$ledgerErrors($org)];
    $received = $results[0]['r']['ok'];
    $status = DB::table('transfer_requests')->where('id', $request->id)->value('status');
    $status === ($received ? 'received' : 'shipped') || $errors[] = "unexpected request status {$status}";
    $held($order->id, $showroom->id) === ($received ? 3.0 : 0.0) || $errors[] = 'partial hold after the race';
    DB::table('stock_transfers')->where('organization_id', $org->id)->count() === ($received ? 1 : 0) || $errors[] = 'stock transfer does not match the outcome';
    $report("B#{$round} receipt vs {$rivals} rival orders", $errors);
    printf("      (receipt %s)\n", $received ? 'won' : 'refused, request stays shipped');
}

$delta = $deadlocks() - $before;
echo "InnoDB deadlocks during run (server-wide): +{$delta}\n";
if ($delta > 0) {
    $failures++;
}
echo ($failures === 0 ? 'PASSED' : 'FAILED')." - {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
