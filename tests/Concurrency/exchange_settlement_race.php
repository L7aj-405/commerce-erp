<?php

/**
 * MySQL concurrency regression for Phase 1 Step 5 (exchange integrity).
 *
 *   php tests/Concurrency/exchange_settlement_race.php [--rivals=4] [--rounds=2] [--database=commerce_erp_dev_test]
 *
 * Real actions, separate PHP processes (separate MySQL sessions), one start barrier. Each round builds its own
 * organisation with a real POS sale, issued invoice and exchange, then races:
 *
 *   A  N sessions fulfil the SAME exchange          -> exactly one addendum, replacement stock consumed once.
 *   B  N sessions each pay 200 of a 300 difference   -> exactly one succeeds; posted total <= difference.
 *   C  N sessions each refund 200 of a 300 target    -> exactly one succeeds; refunded total <= target.
 *   D  one cancellation vs one fulfilment (x2 runs)  -> never "cancelled + delivered"; ledger consistent.
 *   E  N sessions receive the SAME exchange return   -> stock restored once, one credit note issued.
 *
 * Every loser must fail with a ValidationException (never a deadlock / lock-wait timeout / other) and the InnoDB
 * deadlock counter must not move. Safety: local *_test / *_dev MySQL databases only. Requires a migrated database.
 */

use App\Actions\Documents\CreateFullInvoiceFromSalesOrderAction;
use App\Actions\Documents\IssueInvoiceAction;
use App\Actions\Exchanges\CancelCustomerExchangeAction;
use App\Actions\Exchanges\CreateCustomerExchangeAction;
use App\Actions\Exchanges\FulfillCustomerExchangeReplacementAction;
use App\Actions\Exchanges\ReceiveCustomerExchangeReturnAction;
use App\Actions\Exchanges\SettleCustomerExchangePaymentAction;
use App\Actions\Exchanges\SettleCustomerExchangeRefundAction;
use App\Actions\Inventory\OpeningStockAction;
use App\Actions\Pos\CreatePosSaleAction;
use App\Models\CustomerExchange;
use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;
use App\Services\OrganizationCreator;
use App\Services\StoreCreator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
$options = getopt('', ['rivals::', 'rounds::', 'database::', 'child::', 'exchange::', 'user::', 'start::', 'payment::', 'amount::']);
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
        app(ActiveTenantContext::class)->resolve($user);
        $exchange = CustomerExchange::query()->withoutGlobalScopes()->findOrFail((int) $options['exchange']);
        while (microtime(true) < (float) $options['start']) {
            usleep(100);
        }
        match ($options['child']) {
            'fulfil' => app(FulfillCustomerExchangeReplacementAction::class)->execute($user, $exchange),
            'receive' => app(ReceiveCustomerExchangeReturnAction::class)->execute($user, $exchange),
            'cancel' => app(CancelCustomerExchangeAction::class)->execute($user, $exchange, 'race cancel'),
            'pay' => app(SettleCustomerExchangePaymentAction::class)->execute($user, $exchange, [[
                'method' => 'cash', 'financial_account_id' => (int) $options['payment'], 'amount' => (string) $options['amount'],
                'payment_date' => now()->toDateString(), 'reference' => 'RACE',
            ]], (string) Str::uuid()),
            'refund' => app(SettleCustomerExchangeRefundAction::class)->execute($user, $exchange,
                Payment::query()->withoutGlobalScopes()->findOrFail((int) $options['payment']), (string) $options['amount'], 'race refund', (string) Str::uuid()),
        };
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'class' => class_basename($e), 'message' => Str::limit($e->getMessage().' '.json_encode(method_exists($e, 'errors') ? $e->errors() : ''), 300)]);
    }
    exit(0);
}

// ---------------------------------------------------------------- parent
$rivals = max(2, (int) ($options['rivals'] ?? 4));
$rounds = max(1, (int) ($options['rounds'] ?? 2));

/**
 * Builds org + POS sale (1 x Product A at 1000) + issued invoice + exchange against a replacement priced
 * $replacementPrice (stock $replacementStock). Returns [owner, org, warehouse, oldVariant, newVariant, exchange, cashAccount, order].
 */
$world = function (string $label, string $replacementPrice, string $replacementStock) {
    $owner = new User;
    $owner->name = "Exchange race {$label}";
    $owner->email = 'exch-'.Str::lower(Str::random(12)).'@race.local';
    $owner->password = 'race-not-a-login';
    $owner->email_verified_at = now();
    $owner->save();
    $org = app(OrganizationCreator::class)->create($owner, "Exchange race {$label}");
    $store = app(StoreCreator::class)->create($owner, $org, 'Store', Str::upper(Str::random(8)));
    $owner->active_organization_id = $org->id;
    $owner->active_store_id = $store->id;
    $owner->save();
    app(ActiveTenantContext::class)->resolve($owner);
    $org->settings = array_replace($org->settings ?? [], ['return_policy' => ['enabled' => true, 'window_minutes' => 10080, 'allow_partial' => true, 'allow_full' => true,
        'manager_override_allowed' => false, 'require_reason' => true, 'default_disposition' => 'restock']]);
    $org->save();
    $w = new Warehouse;
    $w->organization_id = $org->id;
    $w->name = 'Main';
    $w->code = 'MAIN';
    $w->status = 'active';
    $w->save();
    $mk = function (string $sku, string $price) use ($org) {
        $product = new Product;
        $product->organization_id = $org->id;
        $product->name = $sku;
        $product->status = 'active';
        $product->save();
        $variant = new ProductVariant;
        $variant->organization_id = $org->id;
        $variant->product_id = $product->id;
        $variant->sku = $sku;
        $variant->default_sale_price = $variant->regular_sale_price = $variant->unit_price_ht = $price;
        $variant->status = 'active';
        $variant->save();

        return $variant;
    };
    $old = $mk('RACE-OLD', '1000.0000');
    $new = $mk('RACE-NEW', $replacementPrice);
    app(OpeningStockAction::class)->execute($owner, $org, $w, $old, '2.0000', 'race');
    app(OpeningStockAction::class)->execute($owner, $org, $w, $new, $replacementStock, 'race');
    $cash = new FinancialAccount;
    $cash->organization_id = $org->id;
    $cash->name = $cash->code = 'RACE-CASH';
    $cash->type = 'cash';
    $cash->status = 'active';
    $cash->currency_code = 'MAD';
    $cash->accepted_methods = ['cash'];
    $cash->save();
    $order = app(CreatePosSaleAction::class)->execute($owner, $org, $store, [
        'client_operation_id' => (string) Str::uuid(), 'warehouse_id' => $w->id, 'customer_id' => null,
        'lines' => [['line_type' => 'catalog', 'product_variant_id' => $old->id, 'quantity' => '1.0000', 'discount_type' => 'none', 'discount_value' => '0.0000']],
        'payments' => [['method' => 'cash', 'financial_account_id' => $cash->id, 'amount' => '1000.0000', 'cash_received' => '1000.0000', 'reference' => null]],
    ]);
    $order = SalesOrder::query()->findOrFail($order->id)->load('lines');
    app(IssueInvoiceAction::class)->execute($owner, app(CreateFullInvoiceFromSalesOrderAction::class)->execute($owner, $order));
    $exchange = app(CreateCustomerExchangeAction::class)->execute($owner, $order->fresh(), (string) Str::uuid(),
        [['sales_order_line_id' => $order->lines()->firstOrFail()->id, 'quantity' => '1.0000']],
        [['product_variant_id' => $new->id, 'quantity' => '1.0000']], 'race exchange', 'restock');

    return [$owner, $org, $w, $old, $new, $exchange, $cash, $order];
};
$launch = function (array $jobs, float $start) use ($database): array {
    $env = array_merge(getenv(), ['DB_DATABASE' => $database]);
    $procs = [];
    foreach ($jobs as $job) {
        $cmd = [PHP_BINARY, __FILE__, '--child='.$job['child'], '--exchange='.$job['exchange'], '--user='.$job['user'], '--start='.$start, '--database='.$database,
            '--payment='.($job['payment'] ?? 0), '--amount='.($job['amount'] ?? '0')];
        $errFile = tempnam(sys_get_temp_dir(), 'ex');
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
$unexpected = function (array $results): array {
    $errors = [];
    foreach ($results as $x) {
        if (! $x['r']['ok'] && $x['r']['class'] !== 'ValidationException') {
            $errors[] = 'unexpected outcome (deadlock / timeout / other): '.json_encode($x['r']);
        }
    }

    return $errors;
};
$wins = fn (array $results): int => count(array_filter($results, fn ($x) => $x['r']['ok']));
$balance = fn (Organization $org, ProductVariant $v) => DB::table('inventory_balances')->where('organization_id', $org->id)->where('product_variant_id', $v->id)->first();
$ledger = function (Organization $org): array {
    $errors = [];
    foreach (DB::table('inventory_balances')->where('organization_id', $org->id)->get() as $b) {
        $active = (float) DB::table('inventory_reservations')->where('organization_id', $org->id)->where('warehouse_id', $b->warehouse_id)
            ->where('product_variant_id', $b->product_variant_id)->where('status', 'active')->sum('quantity');
        if (abs((float) $b->reserved - $active) > 0.00005 || (float) $b->reserved < 0 || (float) $b->on_hand < 0) {
            $errors[] = "ledger mismatch variant {$b->product_variant_id}: on_hand {$b->on_hand} reserved {$b->reserved} active {$active}";
        }
    }

    return $errors;
};
$failures = 0;
$report = function (string $label, array $errors) use (&$failures): void {
    printf("  %-52s %s\n", $label, $errors === [] ? 'PASS' : 'FAIL');
    foreach ($errors as $error) {
        echo "      x {$error}\n";
    }
    $failures += $errors === [] ? 0 : 1;
};
$jobs = fn (string $child, CustomerExchange $x, User $u, int $n, array $extra = []) => array_map(fn () => array_merge(['child' => $child, 'exchange' => $x->id, 'user' => $u->id], $extra), range(1, $n));

echo "Exchange race on mysql://{$host}/{$actualDb} - {$rounds} round(s), {$rivals} rivals\n";
$before = $deadlocks();

for ($round = 1; $round <= $rounds; $round++) {
    // A: simultaneous fulfilment (replacement stock 2, price 1300)
    [$owner, $org, $w, $old, $new, $x] = $world("A{$round}", '1300.0000', '2.0000');
    app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $x);
    $res = $launch($jobs('fulfil', $x, $owner, $rivals), microtime(true) + 4.0);
    $errors = $unexpected($res);
    DB::table('sales_order_addenda')->where('sales_order_id', $x->sales_order_id)->count() === 1 || $errors[] = 'expected exactly one addendum';
    (float) $balance($org, $new)->on_hand === 1.0 || $errors[] = 'replacement stock consumed '.(2 - (float) $balance($org, $new)->on_hand).' times';
    DB::table('customer_exchanges')->where('id', $x->id)->value('status') === 'awaiting_settlement' || $errors[] = 'exchange not awaiting_settlement';
    $report("A#{$round} {$rivals} sessions fulfil one exchange", array_merge($errors, $ledger($org)));

    // E: simultaneous reception
    [$owner, $org, $w, $old, $new, $x] = $world("E{$round}", '1300.0000', '2.0000');
    $res = $launch($jobs('receive', $x, $owner, $rivals), microtime(true) + 4.0);
    $errors = $unexpected($res);
    (float) $balance($org, $old)->on_hand === 2.0 || $errors[] = 'returned stock restored '.((float) $balance($org, $old)->on_hand - 1).' times (expected 1)';
    DB::table('credit_notes')->where('organization_id', $org->id)->where('status', 'issued')->count() === 1 || $errors[] = 'expected exactly one issued credit note';
    DB::table('inventory_movements')->where('organization_id', $org->id)->where('movement_type', 'customer_return')->count() === 1 || $errors[] = 'expected exactly one customer_return movement';
    $report("E#{$round} {$rivals} sessions receive one return", array_merge($errors, $ledger($org)));

    // B: payment race (difference 300; each session pays 200)
    [$owner, $org, $w, $old, $new, $x, $cash] = $world("B{$round}", '1300.0000', '2.0000');
    app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $x);
    app(FulfillCustomerExchangeReplacementAction::class)->execute($owner, $x->fresh());
    $res = $launch($jobs('pay', $x, $owner, $rivals, ['payment' => $cash->id, 'amount' => '200.0000']), microtime(true) + 4.0);
    $errors = $unexpected($res);
    $wins($res) === 1 || $errors[] = 'expected exactly 1 payment of 200 to win, got '.$wins($res);
    $posted = (string) DB::table('customer_exchange_payments as l')->join('payments as p', 'p.id', '=', 'l.payment_id')
        ->where('l.customer_exchange_id', $x->id)->where('p.status', 'posted')->sum('p.amount');
    bccomp($posted, '300', 4) <= 0 || $errors[] = "posted exchange payments {$posted} exceed the 300 difference";
    $report("B#{$round} {$rivals} sessions pay 200 of a 300 difference", array_merge($errors, $ledger($org)));

    // C: refund race (target 300; each session refunds 200)
    [$owner, $org, $w, $old, $new, $x, $cash] = $world("C{$round}", '700.0000', '2.0000');
    app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $x);
    app(FulfillCustomerExchangeReplacementAction::class)->execute($owner, $x->fresh());
    $original = Payment::query()->where('organization_id', $org->id)->firstOrFail();
    $res = $launch($jobs('refund', $x, $owner, $rivals, ['payment' => $original->id, 'amount' => '200.0000']), microtime(true) + 4.0);
    $errors = $unexpected($res);
    $wins($res) === 1 || $errors[] = 'expected exactly 1 refund of 200 to win, got '.$wins($res);
    $refunded = (string) DB::table('payment_refunds')->where('organization_id', $org->id)->where('status', 'posted')->sum('amount');
    bccomp($refunded, '300', 4) <= 0 || $errors[] = "refunded {$refunded} exceeds the 300 target";
    $report("C#{$round} {$rivals} sessions refund 200 of a 300 target", array_merge($errors, $ledger($org)));

    // D: cancel vs fulfil, twice with different arrival orders
    foreach ([1, 2] as $run) {
        [$owner, $org, $w, $old, $new, $x] = $world("D{$round}{$run}", '1300.0000', '2.0000');
        app(ReceiveCustomerExchangeReturnAction::class)->execute($owner, $x);
        $mix = [['child' => 'cancel', 'exchange' => $x->id, 'user' => $owner->id], ['child' => 'fulfil', 'exchange' => $x->id, 'user' => $owner->id]];
        $res = $launch($run === 1 ? $mix : array_reverse($mix), microtime(true) + 4.0);
        $errors = $unexpected($res);
        $row = DB::table('customer_exchanges')->where('id', $x->id)->first();
        $addenda = DB::table('sales_order_addenda')->where('sales_order_id', $x->sales_order_id)->count();
        if ($row->status === 'cancelled' && ($addenda !== 0 || (float) $balance($org, $new)->on_hand !== 2.0)) {
            $errors[] = 'cancelled exchange nevertheless delivered goods';
        }
        if ($row->status === 'awaiting_settlement' && ($addenda !== 1 || (float) $balance($org, $new)->on_hand !== 1.0)) {
            $errors[] = 'delivered exchange without a consistent addendum / stock movement';
        }
        in_array($row->status, ['cancelled', 'awaiting_settlement'], true) || $errors[] = "unexpected final status {$row->status}";
        $report("D#{$round}.{$run} cancel vs fulfil (final: {$row->status})", array_merge($errors, $ledger($org)));
    }
}

$delta = $deadlocks() - $before;
echo "InnoDB deadlocks during run (server-wide): +{$delta}\n";
if ($delta > 0) {
    $failures++;
}
echo ($failures === 0 ? 'PASSED' : 'FAILED')." - {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
