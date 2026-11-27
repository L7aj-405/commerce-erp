<?php

/**
 * MySQL concurrency regression for the order-correction reservation incident (UAT 19 Nov 2026, SO-000007).
 *
 *   php tests/Concurrency/order_correction_race.php [--rivals=4] [--rounds=3] [--database=commerce_erp_dev_test]
 *
 * Real actions, separate PHP processes (separate MySQL sessions), one start barrier.
 *
 * Scenario A - "correction vs rivals": a POS-origin order holds the ONLY unit. Its correction is opened (the unit
 *   is freed), then the order's re-confirmation (ConfirmSalesOrderAction) races N rival draft orders that each
 *   confirm for that same unit. Exactly ONE confirmation may win. Whoever wins, the balance must reconcile:
 *   reserved == sum(active reservations) == 1 <= on_hand, every loser is refused with a ValidationException
 *   (never a deadlock / lock-wait timeout / other), and no order is left confirmed without a reservation.
 *
 * Scenario B - "double correction": the same confirmed order is corrected by N sessions at once. Exactly one
 *   StartSalesOrderCorrectionAction may succeed; its reservation is released exactly once (reserved ends at 0,
 *   one released reservation, one in-progress revision).
 *
 * Pass also requires that InnoDB's deadlock counter did not move. Safety: local *_test / *_dev MySQL databases
 * only; every round creates its own organization. Requires a migrated database.
 */

use App\Actions\Inventory\OpeningStockAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\CreateSalesOrderAction;
use App\Actions\Sales\SaveSalesOrderLineAction;
use App\Actions\Sales\StartSalesOrderCorrectionAction;
use App\Enums\SalesOrderSource;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrganizationCreator;
use App\Services\StoreCreator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
$options = getopt('', ['rivals::', 'rounds::', 'database::', 'child::', 'order::', 'user::', 'start::']);
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
        $order = SalesOrder::query()->withoutGlobalScopes()->findOrFail((int) $options['order']);
        while (microtime(true) < (float) $options['start']) {
            usleep(100);
        }
        match ($options['child']) {
            'confirm' => app(ConfirmSalesOrderAction::class)->execute($user, $order),
            'correct' => app(StartSalesOrderCorrectionAction::class)->execute($user, $order, 'race correction'),
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

/** @return array{User, Organization, Warehouse, ProductVariant} */
$world = function (string $label): array {
    $owner = new User;
    $owner->name = "Correction race {$label}";
    $owner->email = 'corr-'.Str::lower(Str::random(12)).'@race.local';
    $owner->password = 'race-not-a-login';
    $owner->email_verified_at = now();
    $owner->save();
    $org = app(OrganizationCreator::class)->create($owner, "Correction race {$label}");
    $store = app(StoreCreator::class)->create($owner, $org, 'Store', Str::upper(Str::random(8)));
    $owner->active_organization_id = $org->id;
    $owner->active_store_id = $store->id;
    $owner->save();
    $w = new Warehouse;
    $w->organization_id = $org->id;
    $w->name = 'Main';
    $w->code = 'MAIN';
    $w->status = 'active';
    $w->save();
    $product = new Product;
    $product->organization_id = $org->id;
    $product->name = 'QA accessory';
    $product->status = 'active';
    $product->save();
    $variant = new ProductVariant;
    $variant->organization_id = $org->id;
    $variant->product_id = $product->id;
    $variant->sku = 'QA-ACC-010';
    $variant->default_sale_price = $variant->regular_sale_price = $variant->unit_price_ht = '100.0000';
    $variant->status = 'active';
    $variant->save();
    app(OpeningStockAction::class)->execute($owner, $org, $w, $variant, '1.0000', 'race');

    return [$owner, $org, $w, $variant];
};
$draft = function (User $owner, Organization $org, Warehouse $w, ProductVariant $variant, SalesOrderSource $source) {
    $store = $org->stores()->firstOrFail();
    $order = app(CreateSalesOrderAction::class)->execute($owner, $org, $store,
        ['customer_id' => null, 'sale_date' => now()->toDateString(), 'currency_code' => 'MAD', 'notes' => null], $source);
    app(SaveSalesOrderLineAction::class)->execute($owner, $order->fresh(), ['line_type' => 'catalog', 'product_variant_id' => $variant->id, 'warehouse_id' => $w->id,
        'quantity' => '1.0000', 'discount_type' => 'none', 'discount_value' => '0.0000']);

    return $order->fresh();
};
$launch = function (array $jobs, float $start) use ($database): array {
    $env = array_merge(getenv(), ['DB_DATABASE' => $database]);
    $procs = [];
    foreach ($jobs as $job) {
        $cmd = [PHP_BINARY, __FILE__, '--child='.$job['child'], '--order='.$job['order'], '--user='.$job['user'], '--start='.$start, '--database='.$database];
        $errFile = tempnam(sys_get_temp_dir(), 'oc');
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
$isRefusal = fn (array $r): bool => ! $r['ok'] && $r['class'] === 'ValidationException';
$ledgerErrors = function (Organization $org): array {
    $errors = [];
    foreach (DB::table('inventory_balances')->where('organization_id', $org->id)->get() as $b) {
        $active = (float) DB::table('inventory_reservations')->where('organization_id', $org->id)->where('warehouse_id', $b->warehouse_id)
            ->where('product_variant_id', $b->product_variant_id)->where('status', 'active')->sum('quantity');
        if (abs((float) $b->reserved - $active) > 0.00005) {
            $errors[] = "reserved {$b->reserved} != active reservations {$active}";
        }
        if ((float) $b->reserved > (float) $b->on_hand + 0.00005 || (float) $b->reserved < 0) {
            $errors[] = "reserved {$b->reserved} outside [0, on_hand {$b->on_hand}]";
        }
    }

    return $errors;
};
$unreservedConfirmed = fn (Organization $org): int => (int) DB::table('sales_orders as o')->where('o.organization_id', $org->id)->where('o.status', 'confirmed')
    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sales_order_lines as l')
        ->join('sales_order_inventory_allocations as a', 'a.sales_order_line_id', '=', 'l.id')
        ->join('inventory_reservations as r', 'r.id', '=', 'a.inventory_reservation_id')
        ->whereColumn('l.sales_order_id', 'o.id')->where('r.status', 'active'))->count();

echo "Order-correction race on mysql://{$host}/{$actualDb} - {$rounds} round(s), {$rivals} rivals\n";
$before = $deadlocks();
$failures = 0;
$report = function (string $label, array $errors) use (&$failures): void {
    printf("  %-44s %s\n", $label, $errors === [] ? 'PASS' : 'FAIL');
    foreach ($errors as $error) {
        echo "      x {$error}\n";
    }
    $failures += $errors === [] ? 0 : 1;
};

for ($round = 1; $round <= $rounds; $round++) {
    // --- Scenario A ---------------------------------------------------------------------------------------------
    [$owner, $org, $w, $variant] = $world("A{$round}");
    $victim = $draft($owner, $org, $w, $variant, SalesOrderSource::Pos);
    app(ConfirmSalesOrderAction::class)->execute($owner, $victim);
    app(StartSalesOrderCorrectionAction::class)->execute($owner, $victim->fresh(), 'race setup');
    $jobs = [['child' => 'confirm', 'order' => $victim->id, 'user' => $owner->id]];
    for ($i = 0; $i < $rivals; $i++) {
        $rival = $draft($owner, $org, $w, $variant, SalesOrderSource::Pos);
        $jobs[] = ['child' => 'confirm', 'order' => $rival->id, 'user' => $owner->id];
    }
    $results = $launch($jobs, microtime(true) + 4.0);
    $errors = [];
    $wins = 0;
    foreach ($results as $x) {
        if ($x['r']['ok']) {
            $wins++;
        } elseif (! $isRefusal($x['r'])) {
            $errors[] = 'unexpected outcome (deadlock / timeout / other): '.json_encode($x['r']);
        }
    }
    $wins === 1 || $errors[] = "expected exactly 1 confirmation to win, got {$wins}";
    foreach ($ledgerErrors($org) as $e) {
        $errors[] = $e;
    }
    $unreservedConfirmed($org) === 0 || $errors[] = 'a confirmed order has no active reservation';
    $victimRow = DB::table('sales_orders')->where('id', $victim->id)->first();
    if ($victimRow->status === 'draft') {
        DB::table('sales_order_revisions')->where('sales_order_id', $victim->id)->value('status') === 'in_progress' || $errors[] = 'refused corrector lost its in-progress revision';
    }
    $report("A#{$round} correction vs {$rivals} rivals (1 unit)", $errors);
    printf("      (winner: %s)\n", $victimRow->status === 'confirmed' ? 'the corrected order' : 'a rival; corrected order stays draft');

    // --- Scenario B ---------------------------------------------------------------------------------------------
    [$owner, $org, $w, $variant] = $world("B{$round}");
    $order = $draft($owner, $org, $w, $variant, SalesOrderSource::Pos);
    app(ConfirmSalesOrderAction::class)->execute($owner, $order);
    $jobs = [];
    for ($i = 0; $i < $rivals; $i++) {
        $jobs[] = ['child' => 'correct', 'order' => $order->id, 'user' => $owner->id];
    }
    $results = $launch($jobs, microtime(true) + 4.0);
    $errors = [];
    $wins = 0;
    foreach ($results as $x) {
        if ($x['r']['ok']) {
            $wins++;
        } elseif (! $isRefusal($x['r'])) {
            $errors[] = 'unexpected outcome (deadlock / timeout / other): '.json_encode($x['r']);
        }
    }
    $wins === 1 || $errors[] = "expected exactly 1 correction to start, got {$wins}";
    foreach ($ledgerErrors($org) as $e) {
        $errors[] = $e;
    }
    (float) DB::table('inventory_balances')->where('organization_id', $org->id)->value('reserved') === 0.0 || $errors[] = 'reservation not released exactly once';
    DB::table('inventory_reservations')->where('organization_id', $org->id)->where('status', 'released')->count() === 1 || $errors[] = 'expected exactly one released reservation';
    DB::table('sales_order_revisions')->where('sales_order_id', $order->id)->where('status', 'in_progress')->count() === 1 || $errors[] = 'expected exactly one in-progress revision';
    $report("B#{$round} {$rivals} simultaneous corrections of one order", $errors);
}

$delta = $deadlocks() - $before;
echo "InnoDB deadlocks during run (server-wide): +{$delta}\n";
if ($delta > 0) {
    $failures++;
}
echo ($failures === 0 ? 'PASSED' : 'FAILED')." - {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
